<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Immutable collection evidence audit identities. The service and cutoff
 * verifier remain responsible for payload and posting consistency.
 */
final class CollectionEvidenceAuditProtection
{
    private const IDENTITY_INDEX = 'fin_collection_audit_identity';
    private const IDENTITY_COLUMN = 'fin_collection_event_key';
    private const EVENTS = "'noncash_claim.submitted', 'noncash_claim.rejected', 'noncash_claim.cleared', 'late_receipt.submitted', 'late_receipt.rejected', 'late_receipt.approved', 'financial.counted_cash_allocated'";

    private function expression(): string
    {
        return 'CASE WHEN event IN ('.self::EVENTS.') THEN event ELSE NULL END';
    }

    private function sqliteIndex(): string
    {
        return 'CREATE UNIQUE INDEX '.self::IDENTITY_INDEX.' ON audit_events (event, entity_id) WHERE event IN ('.self::EVENTS.')';
    }

    private function normalizeExpression(string $expression): string
    {
        // Only recognize escaped delimiters around the seven exact literals.
        // Do not strip backslashes or alter unknown string contents globally.
        foreach (explode(', ', self::EVENTS) as $literal) {
            $name = substr($literal, 1, -1);
            foreach ([1, 2] as $count) {
                $quote = str_repeat(chr(92), $count)."'";
                $expression = str_replace($quote.$name.$quote, $literal, $expression);
            }
        }
        // MySQL renders charset introducers and redundant parentheses in
        // information_schema. Preserve every byte inside quoted event names.
        preg_match_all("/(?:_[a-zA-Z0-9]+)?'[^']*'|[a-zA-Z_][a-zA-Z0-9_]*|[^\s\x60()]/", $expression, $matches);
        return implode('', array_map(static function (string $token): string {
            $quoted = strpos($token, "'");
            return $quoted !== false ? substr($token, $quoted) : strtolower($token);
        }, $matches[0]));
    }

    /** Return a specific failed invariant, not raw schema or credentials. */
    private function identityIssue(): ?string
    {
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')->where('type', 'index')->where('name', self::IDENTITY_INDEX)->first();
            return $row !== null && $row->tbl_name === 'audit_events' && $row->sql === $this->sqliteIndex()
                ? null : 'sqlite_index_definition';
        }
        $column = DB::table('information_schema.COLUMNS')->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'audit_events')->where('COLUMN_NAME', self::IDENTITY_COLUMN)
            ->selectRaw('DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS max_length, EXTRA AS extra, GENERATION_EXPRESSION AS generation_expression')->first();
        if ($column === null) return 'generated_column_missing';
        if (strtolower((string) $column->data_type) !== 'varchar' || (int) $column->max_length !== 80) return 'generated_column_type';
        if (! str_contains(strtoupper((string) $column->extra), 'VIRTUAL')
            || ! str_contains(strtoupper((string) $column->extra), 'INVISIBLE')) return 'generated_column_attributes';
        if ($this->normalizeExpression((string) $column->generation_expression) !== $this->normalizeExpression($this->expression())) {
            return 'generated_column_expression';
        }
        $index = DB::table('information_schema.STATISTICS')->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'audit_events')->where('INDEX_NAME', self::IDENTITY_INDEX)->orderBy('SEQ_IN_INDEX')
            ->selectRaw('COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique, SUB_PART AS sub_part')->get();
        if ($index->count() !== 2 || $index->pluck('column_name')->all() !== [self::IDENTITY_COLUMN, 'entity_id']) return 'index_columns';
        if (! $index->every(fn ($row) => (int) $row->non_unique === 0 && $row->sub_part === null)) return 'index_uniqueness';
        return null;
    }

    private function identityActive(): bool
    {
        return $this->identityIssue() === null;
    }

    public function installIdentity(): void
    {
        if ($this->identityActive()) return;
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared($this->sqliteIndex());
        } else {
            $column = DB::table('information_schema.COLUMNS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'audit_events')->where('COLUMN_NAME', self::IDENTITY_COLUMN)->exists();
            if (! $column) {
                DB::unprepared('ALTER TABLE audit_events ADD COLUMN '.self::IDENTITY_COLUMN
                    .' VARCHAR(80) GENERATED ALWAYS AS ('.$this->expression().') VIRTUAL INVISIBLE');
            }
            $index = DB::table('information_schema.STATISTICS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'audit_events')->where('INDEX_NAME', self::IDENTITY_INDEX)->exists();
            if (! $index) {
                DB::unprepared('CREATE UNIQUE INDEX '.self::IDENTITY_INDEX.' ON audit_events ('.self::IDENTITY_COLUMN.', entity_id)');
            }
        }
        $issue = $this->identityIssue();
        if ($issue !== null) throw new \LogicException('Collection audit identity index incomplete: '.$issue);
    }

    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported collection audit engine.');
        }
        $sqlite = $driver === 'sqlite';
        $current = $sqlite ? '' : ' FOR UPDATE';
        $local = $sqlite ? "date(NEW.occurred_at, '+3 hours')"
            : "DATE(DATE_ADD('1970-01-01 00:00:00', INTERVAL (UNIX_TIMESTAMP(NEW.occurred_at) + 10800) SECOND))";
        $types = [
            'noncash_claim.submitted' => ['noncash_collection_claims', 'noncash_collection_claim', 'submitted_by', 'created_at', null],
            'noncash_claim.rejected' => ['noncash_collection_claims', 'noncash_collection_claim', 'decided_by', 'decided_at', 'rejected'],
            'noncash_claim.cleared' => ['noncash_collection_claims', 'noncash_collection_claim', 'decided_by', 'decided_at', 'cleared'],
            'late_receipt.submitted' => ['late_receipt_reviews', 'late_receipt_review', 'collector_id', 'created_at', null],
            'late_receipt.rejected' => ['late_receipt_reviews', 'late_receipt_review', 'decided_by', 'decided_at', 'rejected'],
            'late_receipt.approved' => ['late_receipt_reviews', 'late_receipt_review', 'decided_by', 'decided_at', 'approved'],
            'financial.counted_cash_allocated' => ['late_receipt_reviews', 'late_receipt_review', 'decided_by', 'decided_at', 'approved'],
        ];
        $events = implode(', ', array_map(fn ($event) => "'{$event}'", array_keys($types)));
        $scope = static fn (string $a): string => "{$a}.event IN ({$events})";
        $identity = [];
        foreach ($types as $event => [$table, $type, $actor, $stamp, $status]) {
            $id = $sqlite ? 'CAST(d.id AS TEXT)' : 'CAST(d.id AS CHAR)';
            $match = "d.{$actor} = NEW.actor_user_id AND {$id} = NEW.entity_id AND d.{$stamp} IS NOT NULL
                AND NEW.occurred_at >= d.{$stamp}";
            if ($status !== null) $match .= " AND d.status = '{$status}'";
            if ($event === 'financial.counted_cash_allocated') $match .= " AND d.settlement_method = 'already_counted'";
            $identity[] = "(NEW.event = '{$event}' AND HEX(NEW.entity_type) = '".strtoupper(bin2hex($type))."'
                AND EXISTS (SELECT 1 FROM {$table} d WHERE {$match}{$current}))";
        }
        $valid = '('.implode(' OR ', $identity).') AND NEW.actor_user_id IS NOT NULL AND NEW.entity_id IS NOT NULL
            AND NEW.occurred_at IS NOT NULL';
        // MySQL cannot take a locking read on the table invoking its own trigger.
        $duplicate = "EXISTS (SELECT 1 FROM audit_events a WHERE a.event = NEW.event AND a.entity_id = NEW.entity_id)";
        $fenced = "EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water' AND s.active_freeze_uuid IS NOT NULL{$current})
            OR EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                AND {$local} >= p.start_on AND {$local} < p.next_on AND (p.state <> 'OPEN'
                OR EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id{$current})
                OR EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.period_id = p.id AND j.action = 'approve'{$current})){$current})";
        $defs = [];
        $add = static function (string $name, string $table, string $event, string $condition, bool $lock = false) use (&$defs, $sqlite, $events): void {
            $condition = preg_replace('/\s+/', ' ', trim($condition));
            $message = 'Collection evidence audit is immutable or invalid';
            $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                : ($lock
                    ? "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; IF NEW.event IN ({$events}) THEN SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END IF; END"
                    : "BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END");
            $defs[$name] = [$table, $event, "BEFORE {$event} ON {$table} FOR EACH ROW {$body}", $body];
        };
        $add('fin_collection_audit_update', 'audit_events', 'UPDATE', "({$scope('OLD')}) OR ({$scope('NEW')})");
        $add('fin_collection_audit_delete', 'audit_events', 'DELETE', $scope('OLD'));
        $insert = "({$scope('NEW')}) AND (NOT COALESCE(({$valid}), 0) OR {$duplicate} OR ({$fenced}))";
        if ($sqlite) $insert = "({$insert}) OR EXISTS (SELECT 1 FROM audit_events a WHERE a.id = NEW.id AND {$scope('a')})";
        $add('fin_collection_audit_insert', 'audit_events', 'INSERT', $insert, true);
        // The audit FK is SET NULL on actor deletion in MySQL.
        $referenced = "EXISTS (SELECT 1 FROM audit_events a WHERE a.actor_user_id = OLD.id AND {$scope('a')})";
        $add('fin_collection_audit_actor_delete', 'users', 'DELETE', $referenced);
        $add('fin_collection_audit_actor_update', 'users', 'UPDATE', "OLD.id <> NEW.id AND {$referenced}");
        if ($sqlite) {
            $add('fin_collection_audit_actor_replace', 'users', 'INSERT',
                "EXISTS (SELECT 1 FROM users u JOIN audit_events a ON a.actor_user_id = u.id
                    WHERE (u.id = NEW.id OR u.email = NEW.email) AND {$scope('a')})");
            $add('fin_collection_audit_actor_replace_update', 'users', 'UPDATE',
                "EXISTS (SELECT 1 FROM users u JOIN audit_events a ON a.actor_user_id = u.id
                    WHERE u.id <> OLD.id AND (u.id = NEW.id OR u.email = NEW.email) AND {$scope('a')})");
            $add('fin_collection_audit_replace_update', 'audit_events', 'UPDATE',
                "EXISTS (SELECT 1 FROM audit_events a WHERE a.id = NEW.id AND a.id <> OLD.id AND {$scope('a')})");
        }
        return $defs;
    }

    private function verified(string $name, array $definition): bool
    {
        [$table, $event, $sql, $body] = $definition;
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
            if ($row === null) return false;
            if ($row->tbl_name !== $table || $row->sql !== "CREATE TRIGGER {$name} {$sql}") {
                throw new \LogicException('Unexpected collection audit trigger: '.$name);
            }
        } else {
            $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
            if ($row === null) return false;
            if ($row->EVENT_OBJECT_TABLE !== $table || $row->EVENT_MANIPULATION !== $event
                || $row->ACTION_TIMING !== 'BEFORE'
                || preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) !== $body) {
                throw new \LogicException('Unexpected collection audit trigger: '.$name);
            }
        }
        return true;
    }

    public function active(): bool
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) return false;
        }
        return $this->identityActive();
    }

    public function install(): void
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) DB::unprepared("CREATE TRIGGER {$name} {$definition[2]}");
        }
        $this->installIdentity();
        if (! $this->active()) throw new \LogicException('Collection evidence audit protection incomplete.');
    }
}

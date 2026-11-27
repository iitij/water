<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Protect the recorded submission and decision cutoffs, not the instrument date.
 * A pending document may be decided in a later open period without rewriting
 * its original evidence. This is not authorization for arbitrary open-period SQL.
 */
final class CollectionEvidencePeriodProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported collection evidence protection driver.');
        }
        $sqlite = $driver === 'sqlite';
        $current = $sqlite ? '' : ' FOR UPDATE';
        $local = static fn (string $timestamp): string => $sqlite
            ? "date({$timestamp}, '+3 hours')"
            : "DATE(DATE_ADD('1970-01-01 00:00:00', INTERVAL (UNIX_TIMESTAMP({$timestamp}) + 10800) SECOND))";
        $terminal = "p.state <> 'OPEN'
            OR EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id{$current})
            OR EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.period_id = p.id AND j.action = 'approve'{$current})";
        $period = static fn (string $stamp, bool $open = false): string =>
            "EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                AND ".$local($stamp)." >= p.start_on AND ".$local($stamp)." < p.next_on
                AND ".($open ? "NOT ({$terminal})" : "({$terminal})")."{$current})";
        $fenced = static fn (string $row): string =>
            $period($row.'.created_at').' OR '.$period($row.'.decided_at').' OR '.$period($row.'.updated_at');
        $freeze = "EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water' AND s.active_freeze_uuid IS NOT NULL{$current})";
        $same = static fn (string $col): string => $sqlite ? "OLD.{$col} IS NEW.{$col}" : "BINARY OLD.{$col} <=> BINARY NEW.{$col}";
        $defs = [];
        foreach ([
            'noncash_collection_claims' => [
                ['submitted_by', 'amount_minor', 'instrument_reference', 'instrument_on'],
                'submitted_by', 'cleared', ['d.id = NEW.id', 'd.sync_uuid = NEW.sync_uuid',
                    'd.payment_id = NEW.payment_id', '(d.payment_method = NEW.payment_method AND d.instrument_reference = NEW.instrument_reference)']],
            'late_receipt_reviews' => [
                ['cash_session_id', 'collector_id', 'submitted_meter_id', 'amount', 'payment_date', 'reference_number', 'reason'],
                'collector_id', 'approved', ['d.id = NEW.id', 'd.sync_uuid = NEW.sync_uuid', 'd.payment_id = NEW.payment_id',
                    'd.evidence_reference = NEW.evidence_reference', 'd.bank_clearance_reference = NEW.bank_clearance_reference']],
        ] as $table => [$source, $owner, $approved, $keys]) {
            $unchanged = implode(' AND ', array_map($same, array_merge(
                ['id', 'sync_uuid', 'subscriber_id', 'invoice_id', 'payment_method', 'notes', 'created_at'], $source)));
            $laterDecision = "OLD.status = 'pending' AND OLD.decided_at IS NULL AND OLD.decided_by IS NULL AND OLD.payment_id IS NULL
                AND NEW.status IN ('rejected', '{$approved}') AND NEW.decided_at IS NOT NULL AND NEW.decided_by IS NOT NULL
                AND NEW.decided_by <> OLD.{$owner} AND NEW.decided_at >= OLD.created_at
                AND NEW.updated_at IS NOT NULL AND NEW.updated_at >= NEW.decided_at
                AND ".$period('NEW.decided_at', true)." AND ".$period('NEW.updated_at', true)."
                AND ({$unchanged})";
            $conditions = [
                'INSERT' => $fenced('NEW'),
                'UPDATE' => '('.$period('NEW.decided_at').') OR ('.$period('NEW.updated_at').')
                    OR (NOT ('.$same('created_at').') AND ('.$period('NEW.created_at').'))
                    OR (('.$fenced('OLD').') AND NOT COALESCE(('.$laterDecision.'), 0))',
                'DELETE' => $fenced('OLD'),
            ];
            if ($sqlite) {
                $collision = implode(' OR ', $keys);
                foreach (['INSERT', 'UPDATE'] as $event) {
                    $exclude = $event === 'UPDATE' ? ' AND d.id <> OLD.id' : '';
                    $conditions[$event] .= " OR EXISTS (SELECT 1 FROM {$table} d WHERE ({$collision}){$exclude} AND (".$fenced('d')."))";
                }
            }
            foreach ($conditions as $event => $condition) {
                $condition = preg_replace('/\s+/', ' ', trim("({$freeze}) OR ({$condition})"));
                $message = 'Collection evidence period is fenced';
                $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                    : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
                $name = 'fin_claim_'.$table.'_'.strtolower($event);
                $defs[$name] = [$table, $event, "BEFORE {$event} ON {$table} FOR EACH ROW {$body}", $body];
            }
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
                throw new \LogicException('Unexpected collection evidence trigger: '.$name);
            }
        } else {
            $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
            if ($row === null) return false;
            if ($row->EVENT_OBJECT_TABLE !== $table || $row->EVENT_MANIPULATION !== $event
                || $row->ACTION_TIMING !== 'BEFORE'
                || preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) !== $body) {
                throw new \LogicException('Unexpected collection evidence trigger: '.$name);
            }
        }
        return true;
    }

    public function active(): bool
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) return false;
        }
        if (DB::getDriverName() !== 'sqlite') {
            foreach (['noncash_collection_claims' => 5, 'late_receipt_reviews' => 6] as $table => $count) {
                $rows = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
                    ->where('TABLE_NAME', $table)->get();
                if ($rows->count() !== $count || $rows->contains(fn ($row) =>
                    ! in_array($row->DELETE_RULE, ['RESTRICT', 'NO ACTION'], true)
                    || ! in_array($row->UPDATE_RULE, ['RESTRICT', 'NO ACTION'], true))) return false;
            }
        }
        return true;
    }

    public function install(): void
    {
        $missing = [];
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) $missing[$name] = $definition;
        }
        foreach ($missing as $name => $definition) {
            DB::unprepared("CREATE TRIGGER {$name} {$definition[2]}");
        }
        if (! $this->active()) throw new \LogicException('Collection evidence protection incomplete.');
    }
}

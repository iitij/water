<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Release documents and refund/release audit evidence require an active,
 * matching open period. Existing immutability guards remain independent.
 * This fence is not an authorization for arbitrary open-period DML. */
final class RefundReleasePeriodProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported refund and release period fence engine.');
        }
        $sqlite = $driver === 'sqlite';
        $lock = $sqlite ? '' : ' FOR UPDATE';
        $local = $sqlite ? "date(NEW.occurred_at, '+3 hours')"
            : "DATE(DATE_ADD('1970-01-01 00:00:00', INTERVAL (UNIX_TIMESTAMP(NEW.occurred_at) + 10800) SECOND))";
        $events = "NEW.event IN ('financial.completed_cash_refund_requested', 'financial.completed_cash_refund_closed',
            'financial.completed_cash_refund_resolution_proposed', 'financial.completed_cash_refund_resolution_approved',
            'financial.completed_cash_refund_resolution_revised', 'financial.completed_cash_refund_batch_approved',
            'financial.completed_cash_refund_document_recorded', 'financial.completed_cash_refund_document_reviewed',
            'financial.completed_cash_refund_source_attested', 'financial.completed_cash_refund',
            'treasury.held_release_proposed', 'treasury.held_release_withdrawn',
            'treasury.held_release_journal_recorded', 'treasury.held_release_activated')";
        $defs = [];
        foreach ([
            'fin_refund_release_audit_period' => ['audit_events', $events, $local, '1 = 1'],
            'fin_held_release_proposal_exact_period' => ['held_cash_release_proposals', '1 = 1', 'NEW.effective_on', 'p.id = NEW.period_id'],
            'fin_held_release_withdraw_exact_period' => ['held_cash_release_withdrawals', '1 = 1', 'NEW.effective_on', 'p.id = NEW.period_id'],
            'fin_held_release_journal_exact_period' => ['held_cash_release_journal', '1 = 1', 'NEW.effective_on', 'p.id = NEW.period_id'],
            'fin_held_release_marker_exact_period' => ['held_cash_active_releases', '1 = 1',
                "(SELECT j.effective_on FROM held_cash_release_journal j WHERE j.id = NEW.journal_id{$lock})",
                "EXISTS (SELECT 1 FROM held_cash_release_journal j WHERE j.id = NEW.journal_id AND j.period_id = p.id
                    AND j.credit_id = NEW.credit_id AND j.treasury_account_id = NEW.treasury_account_id
                    AND j.kind = 'release'{$lock})"],
        ] as $name => [$table, $applies, $date, $identity]) {
            $invalid = "NOT EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water'
                AND s.mode = 'ACTIVE' AND s.active_freeze_uuid IS NULL AND s.currency = 'YER'
                AND s.timezone = 'Asia/Aden'{$lock})
                OR NOT EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                    AND {$date} >= p.start_on AND {$date} < p.next_on AND p.state = 'OPEN' AND ({$identity})
                    AND NOT EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id{$lock})
                    AND NOT EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.period_id = p.id AND j.action = 'approve'{$lock}){$lock})";
            $condition = preg_replace('/\s+/', ' ', trim("({$applies}) AND ({$invalid})"));
            $message = $table === 'audit_events' ? 'Refund or release audit period is fenced' : 'Held cash source period is fenced';
            $body = $sqlite
                ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; IF {$applies} THEN SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$invalid} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END IF; END";
            $body = preg_replace('/\s+/', ' ', trim($body));
            $defs[$name] = [$table, "BEFORE INSERT ON {$table} FOR EACH ROW {$body}", $body];
        }
        return $defs;
    }

    private function verified(string $name, array $definition): bool
    {
        [$table, $sql, $body] = $definition;
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
            return $row !== null && $row->tbl_name === $table && $row->sql === "CREATE TRIGGER {$name} {$sql}";
        }
        $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
        return $row !== null && $row->EVENT_OBJECT_TABLE === $table && $row->EVENT_MANIPULATION === 'INSERT'
            && $row->ACTION_TIMING === 'BEFORE' && preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) === $body;
    }

    public function active(): bool
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) return false;
        }
        return true;
    }

    public function install(): void
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) DB::unprepared("CREATE TRIGGER {$name} {$definition[1]}");
        }
        if (! $this->active()) throw new \LogicException('Refund and release period protection incomplete.');
    }
}

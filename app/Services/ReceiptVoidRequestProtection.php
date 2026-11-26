<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/** Bind registered receipt request/decision projections to durable audit evidence. */
final class ReceiptVoidRequestProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported receipt request protection driver.');
        }
        $sqlite = $driver === 'sqlite';
        $eq = static fn (string $a, string $b): string => $sqlite
            ? "{$a} IS {$b}" : "BINARY {$a} <=> BINARY {$b}";
        $json = static function (string $alias, string $key) use ($sqlite): string {
            $value = "JSON_EXTRACT({$alias}.".chr(96)."after".chr(96).", '$.{$key}')";
            return $sqlite ? $value : "(CASE WHEN JSON_TYPE({$value}) = 'NULL' THEN NULL ELSE JSON_UNQUOTE({$value}) END)";
        };
        $hex = strtoupper(bin2hex(Payment::class));
        $events = "'payment.void_requested', 'financial.void_request_gated', 'payment.void_approved', 'payment.void_rejected'";
        $auditScope = static fn (string $a): string => "HEX({$a}.entity_type) = '{$hex}' AND {$a}.event IN ({$events})";
        $registered = "(EXISTS (SELECT 1 FROM transactions t JOIN financial_posting_events e
            ON e.source_type = 'ledger_transaction' AND e.scope_key = 'water' AND e.leg = 'subscriber'
            AND e.source_id = CAST(t.id AS CHAR)
            WHERE HEX(t.reference_type) = '{$hex}' AND t.reference_id = OLD.id
            AND t.type IN ('payment', 'payment_reversal'))
            OR EXISTS (SELECT 1 FROM financial_posting_operations o WHERE o.scope_key = 'water' AND o.operation_uuid = OLD.sync_uuid))";
        $latest = "(SELECT MAX(a.id) FROM audit_events a WHERE {$auditScope('a')} AND a.entity_id = CAST(OLD.id AS CHAR))";
        $requestSame = implode(' AND ', array_map(fn ($col) => $eq("OLD.{$col}", "NEW.{$col}"),
            ['void_requested_at', 'void_requested_by', 'void_request_reason']));
        $decisionSame = implode(' AND ', array_map(fn ($col) => $eq("OLD.{$col}", "NEW.{$col}"),
            ['void_decision_notes', 'void_decided_by', 'void_decided_at']));
        $requestClear = 'NEW.void_requested_at IS NULL AND NEW.void_requested_by IS NULL AND NEW.void_request_reason IS NULL';
        $decisionClear = 'NEW.void_decision_notes IS NULL AND NEW.void_decided_by IS NULL AND NEW.void_decided_at IS NULL';
        $proofRequest = "EXISTS (SELECT 1 FROM audit_events a WHERE a.id = {$latest}
            AND a.event = 'payment.void_requested' AND a.source = 'gated'
            AND a.actor_user_id = NEW.void_requested_by
            AND ({$eq($json('a', 'reason'), 'NEW.void_request_reason')})
            AND ({$eq($json('a', 'requested_at'), 'NEW.void_requested_at')}))";
        $create = "OLD.voided_at IS NULL AND NEW.voided_at IS NULL
            AND OLD.void_requested_at IS NULL AND OLD.void_requested_by IS NULL AND OLD.void_request_reason IS NULL
            AND NEW.void_requested_at IS NOT NULL AND NEW.void_requested_by IS NOT NULL
            AND NEW.void_request_reason IS NOT NULL AND LENGTH(TRIM(NEW.void_request_reason)) >= 10
            AND ({$decisionClear}) AND ({$proofRequest})";
        $prior = "(SELECT MAX(b.id) FROM audit_events b WHERE {$auditScope('b')}
            AND b.entity_id = CAST(OLD.id AS CHAR) AND b.id < a.id)";
        $decisionProof = static function (string $event) use ($latest, $prior, $eq, $json): string {
            return "EXISTS (SELECT 1 FROM audit_events a JOIN audit_events p ON p.id = {$prior}
                WHERE a.id = {$latest} AND a.event = '{$event}' AND a.source = 'gated'
                AND a.actor_user_id = NEW.void_decided_by
                AND ({$eq($json('a', 'notes'), 'NEW.void_decision_notes')})
                AND ({$eq($json('a', 'decided_at'), 'NEW.void_decided_at')})
                AND p.event = 'financial.void_request_gated' AND p.actor_user_id = OLD.void_requested_by
                AND ({$eq($json('p', 'reason'), 'OLD.void_request_reason')})
                AND ({$eq($json('p', 'requested_at'), 'OLD.void_requested_at')}))";
        };
        $decisionBase = "OLD.void_requested_at IS NOT NULL AND OLD.void_requested_by IS NOT NULL
            AND OLD.void_decided_by IS NULL AND OLD.void_decided_at IS NULL
            AND NEW.void_decided_by IS NOT NULL AND NEW.void_decided_at IS NOT NULL
            AND NEW.void_decided_by <> OLD.void_requested_by";
        $reject = "({$decisionBase}) AND OLD.voided_at IS NULL AND NEW.voided_at IS NULL
            AND ({$requestClear}) AND ({$decisionProof('payment.void_rejected')})";
        $approve = "({$decisionBase}) AND OLD.voided_at IS NOT NULL AND NEW.voided_at IS NOT NULL
            AND OLD.voided_via = 'approval' AND OLD.voided_by = NEW.void_decided_by
            AND ({$requestSame}) AND ({$decisionProof('payment.void_approved')})";
        $defs = [];
        $add = function (string $name, string $table, string $event, string $condition, string $message) use (&$defs, $sqlite): void {
            $condition = preg_replace('/\s+/', ' ', trim($condition));
            $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                : "BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
            $defs[$name] = [$table, $event, "BEFORE {$event} ON {$table} FOR EACH ROW {$body}", $body];
        };
        $add('reg_receipt_request_update', 'payments', 'UPDATE',
            "{$registered} AND NOT COALESCE((({$requestSame}) AND ({$decisionSame})) OR ({$create}) OR ({$reject}) OR ({$approve}), 0)",
            'Registered receipt request requires audit evidence');
        // Protect only the audit events that serve as receipt request evidence.
        $add('receipt_request_audit_update', 'audit_events', 'UPDATE',
            "({$auditScope('OLD')}) OR ({$auditScope('NEW')})", 'Receipt request audit is immutable');
        $add('receipt_request_audit_delete', 'audit_events', 'DELETE', $auditScope('OLD'), 'Receipt request audit is immutable');
        if ($sqlite) {
            $add('receipt_request_audit_replace', 'audit_events', 'INSERT',
                "EXISTS (SELECT 1 FROM audit_events a WHERE a.id = NEW.id AND {$auditScope('a')})", 'Receipt request audit is immutable');
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
                throw new \LogicException('Unexpected receipt request trigger: '.$name);
            }
        } else {
            $row = FinancialTriggerMetadata::query()
                ->where('TRIGGER_NAME', $name)->first();
            if ($row === null) return false;
            if ($row->EVENT_OBJECT_TABLE !== $table || $row->EVENT_MANIPULATION !== $event
                || $row->ACTION_TIMING !== 'BEFORE'
                || preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) !== $body) {
                throw new \LogicException('Unexpected receipt request trigger: '.$name);
            }
        }
        return true;
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
            if (! $this->verified($name, $definition)) DB::unprepared("CREATE TRIGGER {$name} {$definition[2]}");
        }
        if (! $this->active()) throw new \LogicException('Receipt request protection incomplete.');
    }
}

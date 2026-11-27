<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Preserve the existing registry-first assignment/payout write sequence.
 * Validate both legs at source insertion, and the source-first credit leg
 * at registry insertion. Bidirectional diagnostic still detects orphan legs.
 */
final class HeldRegistryLinkProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported held registry linkage engine.');
        }
        $sqlite = $driver === 'sqlite';
        $lock = $sqlite ? '' : ' FOR UPDATE';
        $defs = [];
        $add = static function (string $name, string $table, string $condition, string $scope, string $message)
            use (&$defs, $sqlite): void {
            $condition = preg_replace('/\s+/', ' ', trim($condition));
            $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = {$scope} FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
            $defs[$name] = [$table, "BEFORE INSERT ON {$table} FOR EACH ROW {$body}", $body, 'INSERT'];
        };
        foreach ([
            'treasury_held_cash_assignments' => ['held_cash_assignment', 'identified_liability', 'NEW.amount_minor', 'unidentified_liability', '-NEW.amount_minor'],
            'treasury_held_cash_payouts' => ['held_cash_payout', 'identified_liability', '-NEW.amount_minor', 'treasury', '-NEW.amount_minor'],
        ] as $table => [$type, $legA, $amountA, $legB, $amountB]) {
            $valid = "EXISTS (SELECT 1 FROM financial_posting_operations o
                JOIN financial_posting_events a ON a.operation_uuid = o.operation_uuid
                JOIN financial_posting_events b ON b.operation_uuid = o.operation_uuid
                WHERE HEX(o.operation_uuid) = HEX(NEW.operation_uuid) AND o.scope_key = 'water'
                AND o.effective_on = NEW.effective_on AND NEW.amount_minor > 0
                AND a.scope_key = o.scope_key AND b.scope_key = o.scope_key
                AND a.source_type = '{$type}' AND b.source_type = '{$type}'
                AND HEX(a.source_id) = HEX(NEW.operation_uuid) AND HEX(b.source_id) = HEX(NEW.operation_uuid)
                AND a.leg = '{$legA}' AND b.leg = '{$legB}' AND a.amount_minor = {$amountA}
                AND b.amount_minor = {$amountB} AND a.currency = 'YER' AND b.currency = 'YER'
                AND HEX(a.source_hash) = HEX(b.source_hash)
                AND NOT EXISTS (SELECT 1 FROM financial_posting_events x
                    WHERE x.operation_uuid = o.operation_uuid AND x.id NOT IN (a.id, b.id){$lock}){$lock})";
            $add('fin_held_link_'.$type, $table,
                "EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water'{$lock}) AND NOT COALESCE(({$valid}), 0)",
                "'water'", 'Held cash source period is fenced');
        }
        $amount = $sqlite ? 'd.amount = -NEW.amount_minor / 100.0' : 'd.amount * 100 = -NEW.amount_minor';
        $credit = "NEW.leg = 'identified_liability' AND NEW.amount_minor < 0
            AND EXISTS (SELECT 1 FROM treasury_held_cash_credits d
                JOIN financial_posting_operations o ON o.operation_uuid = NEW.operation_uuid
                AND o.scope_key = NEW.scope_key
                WHERE HEX(CAST(d.id AS CHAR)) = HEX(NEW.source_id)
                AND HEX(d.operation_uuid) = HEX(o.operation_uuid)
                AND d.effective_on = o.effective_on AND {$amount}{$lock})";
        $add('fin_held_link_credit_event', 'financial_posting_events',
            "NEW.source_type = 'held_cash_credit' AND NOT COALESCE(({$credit}), 0)",
            'NEW.scope_key', 'Financial registry source identity is invalid');
        return $defs;
    }

    private function verified(string $name, array $definition): bool
    {
        [$table, $sql, $body, $event] = $definition;
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
            return $row !== null && $row->tbl_name === $table && $row->sql === "CREATE TRIGGER {$name} {$sql}";
        }
        $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
        return $row !== null && $row->EVENT_OBJECT_TABLE === $table && $row->EVENT_MANIPULATION === $event
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
        $missing = [];
        foreach ($this->definitions() as $name => $definition) {
            if ($this->verified($name, $definition)) continue;
            $exists = DB::getDriverName() === 'sqlite'
                ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->exists()
                : FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->isNotEmpty();
            if ($exists) throw new \LogicException('Unexpected HeldRegistryLinkProtection trigger: '.$name);
            $missing[$name] = $definition;
        }
        foreach ($missing as $name => $definition) {
            DB::unprepared("CREATE TRIGGER {$name} {$definition[1]}");
        }
        if (! $this->active()) throw new \LogicException('Held registry linkage protection incomplete.');
    }
}

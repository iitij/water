<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Verify the recovery document guards installed by migrations 000144 and 000146. */
final class CustodyShortageRecoveryProtection
{
    public function active(): bool
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) return false;
        $sqlite = $driver === 'sqlite';
        $normalize = static fn (string $sql): string => preg_replace('/\s+/', ' ', trim($sql));
        $message = 'Custody shortage recovery is immutable';
        $definitions = [];
        foreach (['UPDATE', 'DELETE'] as $event) {
            $definitions['fin_shortage_recovery_'.strtolower($event)] = [$event,
                $sqlite ? "BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                    : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'"];
        }
        if ($sqlite) {
            $definitions['fin_shortage_recovery_replace'] = ['INSERT',
                "WHEN EXISTS (SELECT 1 FROM custody_shortage_recoveries r WHERE r.id = NEW.id
                    OR r.treasury_posting_id = NEW.treasury_posting_id
                    OR r.proposal_id = NEW.proposal_id OR r.reference = NEW.reference OR r.operation_uuid = NEW.operation_uuid)
                BEGIN SELECT RAISE(ABORT, '{$message}'); END"];
        }
        foreach ($definitions as $name => [$event, $body]) {
            if ($sqlite) {
                $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
                if (! $row || $row->tbl_name !== 'custody_shortage_recoveries'
                    || $normalize($row->sql) !== $normalize("CREATE TRIGGER {$name} BEFORE {$event} ON custody_shortage_recoveries FOR EACH ROW {$body}")) return false;
            } else {
                $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
                if (! $row || $row->EVENT_OBJECT_TABLE !== 'custody_shortage_recoveries'
                    || $row->EVENT_MANIPULATION !== $event || $row->ACTION_TIMING !== 'BEFORE'
                    || $normalize($row->ACTION_STATEMENT) !== $normalize($body)) return false;
            }
        }
        return true;
    }
}

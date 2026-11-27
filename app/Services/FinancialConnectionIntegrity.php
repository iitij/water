<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\DB;

/** Check actual write-session constraint settings, not merely schema metadata.
 * This does not authenticate arbitrary SQL or certify previously stored rows.
 */
final class FinancialConnectionIntegrity
{
    public function active(): bool
    {
        $pdo = DB::connection()->getPdo();
        return match (DB::getDriverName()) {
            'mysql', 'mariadb' => (string) $pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn() === '1'
                && (string) $pdo->query('SELECT @@SESSION.unique_checks')->fetchColumn() === '1',
            'sqlite' => (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === '1'
                && (string) $pdo->query('PRAGMA ignore_check_constraints')->fetchColumn() === '0',
            default => false,
        };
    }

    public function requireActive(): void
    {
        if (! $this->active()) throw new DomainException('financial_connection_constraints_disabled');
    }
}

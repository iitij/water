<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OverflowException;
use RuntimeException;

/**
 * Atomic document allocator. Production cutover requires all writers to switch together.
 * All writers must switch together after a write-fenced legacy inventory.
 */
final class DocumentSequenceService
{
    private const MAX_NUMBER = 999999999999999;

    /** Production issuance must reserve inside its document transaction. */
    public function reserveForIssue(string $kind, string $period): string
    {
        if (DB::connection()->transactionLevel() === 0) {
            throw new \LogicException('Document issuance requires an outer transaction.');
        }

        return $this->next($kind, $period);
    }

    /**
     * Reserve an invoice or receipt number for an explicit issuance month (YYYYMM).
     * Call inside the same outer transaction as document creation. Do not publish
     * a number until commit; a rolled-back reservation may be reused.
     */
    public function next(string $kind, string $period): string
    {
        [$table, $column] = match ($kind) {
            'INV' => ['invoices', 'bill_number'],
            'REC' => ['payments', 'receipt_number'],
            default => throw new InvalidArgumentException('Unsupported document kind.'),
        };
        if (! preg_match('/\A[1-9][0-9]{3}(?:0[1-9]|1[0-2])\z/', $period)) {
            throw new InvalidArgumentException('Expected an issuance month in YYYYMM format.');
        }
        if (PHP_INT_SIZE < 8) {
            throw new RuntimeException('Document sequences require 64-bit integers.');
        }

        $db = DB::connection();
        $key = ['kind' => $kind, 'period' => $period];
        $prefix = $kind.'-'.$period.'-';

        return $db->transaction(function () use ($db, $key, $prefix, $table, $column): string {
            // UPSERT takes an exclusive duplicate-key lock on MySQL, unlike
            // INSERT IGNORE (shared lock followed by a lock upgrade can deadlock).
            // Updating the unchanged key never resets an existing high-water mark.
            $db->table('document_sequences')->upsert(
                [$key + ['last_number' => 0]], ['kind', 'period'], ['kind']
            );
            $row = $db->table('document_sequences')->where($key)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Sequence row missing after initialization.');
            }
            $last = $this->parseNumber((string) $row->last_number);
            if ($last === 0) {
                // Direct table access deliberately includes soft-deleted documents.
                $last = $this->legacyMaximum($db, $table, $column, $prefix);
            }
            if ($last >= self::MAX_NUMBER) {
                throw new OverflowException('Document sequence exhausted.');
            }
            $next = $last + 1;
            $updated = $db->table('document_sequences')->where($key)->update(['last_number' => $next]);
            if ($updated !== 1) {
                throw new RuntimeException('Sequence update did not affect exactly one row.');
            }

            return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        }, $db->transactionLevel() === 0 ? 3 : 1);
    }

    private function legacyMaximum(Connection $db, string $table, string $column, string $prefix): int
    {
        $last = 0;
        // Uppercase comparison avoids collisions with case-insensitive unique indexes.
        foreach ($db->table($table)->select($column)
            ->whereRaw('UPPER('.$column.') LIKE ?', [$prefix.'%'])->cursor() as $row) {
            $value = (string) $row->{$column};
            if (preg_match('/\A'.preg_quote($prefix, '/').'([0-9]+)\z/i', $value, $matches)) {
                $last = max($last, $this->parseNumber($matches[1]));
            }
        }

        return $last;
    }

    private function parseNumber(string $digits): int
    {
        if (! preg_match('/\A[0-9]+\z/', $digits)) {
            throw new RuntimeException('Invalid persisted sequence value.');
        }
        $digits = ltrim($digits, '0');
        if (strlen($digits) > 15) {
            throw new OverflowException('Persisted sequence exceeds supported range.');
        }

        return (int) $digits;
    }
}

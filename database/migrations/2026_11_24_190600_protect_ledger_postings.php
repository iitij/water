<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \RuntimeException('Unsupported ledger protection database.');
        }
        $duplicate = DB::table('transactions')->whereNotNull('reference_type')->whereNotNull('reference_id')
            ->groupBy('reference_type', 'reference_id', 'type')->havingRaw('COUNT(*) > 1')
            ->select('reference_type', 'reference_id', 'type')->first();
        if ($duplicate) {
            throw new \RuntimeException('Duplicate referenced ledger postings: run ledger:audit and resolve explicitly before migrating.');
        }
        if (! Schema::hasIndex('transactions', 'transactions_reference_posting_unique', 'unique')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->unique(['reference_type', 'reference_id', 'type'], 'transactions_reference_posting_unique');
            });
        }
        if ($driver === 'sqlite') {
            app(\App\Services\LedgerProtection::class)->install();
            return;
        }

        // MySQL cascades bypass row triggers. Keep restrictive FKs even when
        // the hosting account lacks permission to install triggers.
        foreach (['subscriber_id' => 'subscribers', 'meter_id' => 'meters'] as $column => $parent) {
            $name = 'transactions_'.$column.'_foreign';
            $restrictName = 'transactions_'.$column.'_ledger_restrict';
            $constraints = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'transactions')->whereIn('CONSTRAINT_NAME', [$name, $restrictName])->get();
            if ($constraints->count() !== 1) {
                throw new \RuntimeException("Expected exactly one ledger foreign key for $column.");
            }
            $constraint = $constraints->first();
            if (! in_array($constraint->DELETE_RULE, ['RESTRICT', 'NO ACTION'], true)) {
                if ($constraint->CONSTRAINT_NAME !== $name) {
                    throw new \RuntimeException("Unexpected ledger foreign key rule for $column.");
                }
                // Use a distinct constraint symbol in the same atomic ALTER.
                // MariaDB can reject DROP + ADD of the same symbol (errno 121).
                DB::statement("ALTER TABLE transactions DROP FOREIGN KEY $name, ADD CONSTRAINT $restrictName FOREIGN KEY ($column) REFERENCES $parent(id) ON DELETE RESTRICT");
            }
        }
        // Explicit privileged install/check required before production acceptance.
    }

    public function down(): void
    {
        throw new \RuntimeException('Ledger integrity protection cannot be removed by rollback.');
    }
};

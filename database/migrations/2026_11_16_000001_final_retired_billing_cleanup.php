<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final compatibility cleanup for databases upgraded from older builds.
 * New installations never create the retired feature's schema.
 *
 * A financial guard prevents irreversible loss of recorded receivables:
 * import the independently reconciled, scrubbed snapshot first if any
 * retired invoice money is present. This migration never rewrites receipts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            if (Schema::hasColumn('invoices', 'tanker_charges')
                && DB::table('invoices')->where('tanker_charges', '<>', 0)->exists()) {
                throw new RuntimeException('Retired billing charges must be reconciled before schema cleanup.');
            }
            if (Schema::hasColumn('invoices', 'invoice_type')
                && DB::table('invoices')->where('invoice_type', 'tanker')->exists()) {
                throw new RuntimeException('Retired invoices must be reconciled before schema cleanup.');
            }
        }

        if (Schema::hasTable('invoice_items')
            && DB::table('invoice_items')->where('type', 'tanker')->exists()) {
            throw new RuntimeException('Retired invoice items must be reconciled before schema cleanup.');
        }

        if (Schema::hasTable('service_requests')) {
            Schema::drop('service_requests');
        }

        if (Schema::hasColumn('invoices', 'tanker_charges')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropColumn('tanker_charges');
            });
        }

        foreach (['tenant_name', 'tenant_phone'] as $column) {
            if (Schema::hasColumn('meters', $column)) {
                Schema::table('meters', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }

        if (Schema::hasTable('settings')) {
            // Cover prefix, infix AND suffix keys (e.g. inv_show_tanker).
            DB::table('settings')
                ->where('key', 'like', '%tanker%')
                ->orWhere('key', 'like', '%service_request%')
                ->orWhere('key', 'like', '%tenant%')
                ->delete();

            // A custom message might include a non-conditional obsolete charge.
            // Reset only affected invoice/WhatsApp templates to new code defaults.
            DB::table('settings')
                ->where(function ($query): void {
                    $query->where('key', 'like', '%template%')
                        ->orWhere('key', 'like', '%message%');
                })
                ->where(function ($query): void {
                    $query->where('value', 'like', '%tanker_fee%')
                        ->orWhere('value', 'like', '%وايت%');
                })
                ->delete();

            // Older, user-edited message templates may still have an inactive
            // conditional line. Remove only that line and keep the rest intact.
            DB::table('settings')
                ->whereNotNull('value')
                ->where('value', 'like', '%tanker_fee%')
                ->orderBy('id')
                ->get(['id', 'value'])
                ->each(static function ($setting): void {
                    $updated = preg_replace(
                        '/\\{if:tanker_fee\\}[\\s\\S]*?\\{\\/if:tanker_fee\\}\\r?\\n?/',
                        '',
                        (string) $setting->value
                    );
                    if ($updated !== $setting->value) {
                        DB::table('settings')->where('id', $setting->id)->update(['value' => $updated]);
                    }
                });
        }

        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')
                ->where('name', 'like', '%ServiceRequest%')
                ->orWhere('name', 'like', '%service_request%')
                ->orWhere('name', 'like', '%tanker%')
                ->pluck('id');
            if ($ids->isNotEmpty()) {
                foreach (['role_has_permissions', 'model_has_permissions'] as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->whereIn('permission_id', $ids)->delete();
                    }
                }
                DB::table('permissions')->whereIn('id', $ids)->delete();
                app('cache')->store(config('permission.cache.store') !== 'default'
                    ? config('permission.cache.store') : null)
                    ->forget(config('permission.cache.key'));
            }
        }

        // Removed migration files must also disappear from the historical
        // migration index so there are no orphaned migration records.
        DB::table('migrations')->whereIn('migration', [
            '2024_01_01_000005_create_service_requests_table',
            '2026_07_21_231307_add_invoice_id_to_service_requests_table',
            '2026_06_16_161149_add_tenant_name_to_meters_table',
            '2026_11_13_220000_drop_tenant_identity_from_meters',
        ])->delete();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Irreversible cleanup: restore a verified database backup instead of rolling back.'
        );
    }
};

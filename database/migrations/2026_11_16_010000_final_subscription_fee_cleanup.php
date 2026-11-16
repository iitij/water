<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Irreversible retirement; never silently destroy unpaid fees or mixed invoices. */
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('subscription_fees')
            && DB::table('subscription_fees')->where('amount', '<>', 0)->exists()) {
            throw new RuntimeException('Reconcile non-zero subscription fee records before removal.');
        }
        if (Schema::hasTable('invoices')) {
            if (Schema::hasColumn('invoices', 'subscription_installment')
                && DB::table('invoices')->where('subscription_installment', '<>', 0)->exists()) {
                throw new RuntimeException('Reconcile invoiced subscription charges before removal.');
            }
            if (Schema::hasColumn('invoices', 'invoice_type')
                && DB::table('invoices')->where('invoice_type', 'subscription')->exists()) {
                throw new RuntimeException('Reconcile subscription-only invoices before removal.');
            }
        }
        if (Schema::hasTable('invoice_items')
            && DB::table('invoice_items')->where('type', 'subscription')->exists()) {
            throw new RuntimeException('Reconcile subscription line items before removal.');
        }
        foreach (['initial_subscription_fee', 'subscription_fee_paid'] as $column) {
            if (Schema::hasColumn('subscribers', $column)
                && DB::table('subscribers')->where($column, '<>', 0)->exists()) {
                throw new RuntimeException('Reconcile subscriber subscription-fee values before removal.');
            }
        }
        Schema::dropIfExists('subscription_fees');
        if (Schema::hasTable('subscribers')) {
            foreach (['initial_subscription_fee', 'subscription_fee_paid', 'is_subscription_fee_fully_paid'] as $column) {
                if (Schema::hasColumn('subscribers', $column)) {
                    Schema::table('subscribers', static function (Blueprint $table) use ($column): void {
                        $table->dropColumn($column);
                    });
                }
            }
        }
        if (Schema::hasColumn('invoices', 'subscription_installment')) {
            Schema::table('invoices', static function (Blueprint $table): void {
                $table->dropColumn('subscription_installment');
            });
        }
        if (Schema::hasTable('settings')) {
            DB::table('settings')->whereIn('key', [
                'subscription_fee',
                'subscription_invoice_template',
                'whatsapp_subscription_message',
            ])->orWhere('key', 'like', '%sub_fee%')
                ->orWhere('key', 'like', 'sub_inv_%')
                ->orWhere('key', 'like', 'sub_show_%')
                ->orWhere('key', 'like', 'sub_lbl_%')
                ->delete();

            // Retain unrelated water-invoice customizations. Remove only
            // retired charge lines, not a complete customer-facing template.
            $templates = DB::table('settings')
                ->where(function ($query): void {
                    $query->where('key', 'like', '%template%')
                        ->orWhere('key', 'like', '%message%');
                })->whereNotNull('value')->get(['id', 'value']);

            foreach ($templates as $template) {
                $original = (string) $template->value;
                $lines = preg_split('/\r\n|\n|\r/', $original) ?: [$original];
                $retained = array_values(array_filter($lines, static function (string $line): bool {
                    return ! str_contains($line, 'subscription_fee')
                        && ! str_contains($line, 'رسوم الاشتراك')
                        && ! str_contains($line, 'رسوم اشتراك');
                }));
                if (count($retained) === count($lines)) {
                    continue;
                }
                $cleaned = trim(implode("\n", $retained));
                if ($cleaned === '') {
                    DB::table('settings')->where('id', $template->id)->delete();
                } else {
                    DB::table('settings')->where('id', $template->id)->update(['value' => $cleaned]);
                }
            }
        }
        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->where('name', 'like', '%SubscriptionFee%')->pluck('id');
            if ($ids->isNotEmpty()) {
                foreach (['role_has_permissions', 'model_has_permissions'] as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->whereIn('permission_id', $ids)->delete();
                    }
                }
                DB::table('permissions')->whereIn('id', $ids)->delete();
                app('cache')->store(config('permission.cache.store') !== 'default'
                    ? config('permission.cache.store') : null)->forget(config('permission.cache.key'));
            }
        }
        DB::table('migrations')->where('migration', '2026_07_22_034823_create_subscription_fees_table')->delete();
    }
    public function down(): void {
        throw new RuntimeException('Irreversible subscription-fee cleanup: restore a verified backup.');
    }
};

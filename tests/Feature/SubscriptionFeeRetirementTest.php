<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubscriptionFeeRetirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_database_has_no_retired_subscription_fee_columns_or_table(): void
    {
        $this->assertFalse(Schema::hasTable('subscription_fees'));
        foreach (['initial_subscription_fee', 'subscription_fee_paid', 'is_subscription_fee_fully_paid'] as $column) {
            $this->assertFalse(Schema::hasColumn('subscribers', $column));
        }
        $this->assertFalse(Schema::hasColumn('invoices', 'subscription_installment'));
    }

    public function test_cleanup_retains_unrelated_custom_invoice_template_lines(): void
    {
        DB::table('settings')->insert([
            ['key' => 'reading_invoice_message_template',
             'value' => "مرحباً {subscriber_name}\n{if:subscription_fee}رسوم الاشتراك: {subscription_fee}{/if:subscription_fee}\nالإجمالي: {total_amount}"],
            ['key' => 'subscription_invoice_template', 'value' => 'old standalone fee template'],
            ['key' => 'whatsapp_subscription_message', 'value' => 'custom legacy text without tokens'],
            ['key' => 'whatsapp_payment_message', 'value' => 'سند قبض: {receipt_number}'],
        ]);

        $migration = require database_path('migrations/2026_11_16_010000_final_subscription_fee_cleanup.php');
        $migration->up();

        $this->assertDatabaseHas('settings', [
            'key' => 'reading_invoice_message_template',
            'value' => "مرحباً {subscriber_name}\nالإجمالي: {total_amount}",
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'whatsapp_payment_message',
            'value' => 'سند قبض: {receipt_number}',
        ]);
        $this->assertDatabaseMissing('settings', ['key' => 'subscription_invoice_template']);
        $this->assertDatabaseMissing('settings', ['key' => 'whatsapp_subscription_message']);
    }
}

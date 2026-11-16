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

    public function test_nonzero_legacy_subscription_fee_is_not_silently_discarded(): void
    {
        Schema::create('subscription_fees', static function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->decimal('amount', 12, 2)->default(0);
        });
        DB::table('subscription_fees')->insert(['amount' => 175]);
        try {
            $migration = require database_path('migrations/2026_11_16_010000_final_subscription_fee_cleanup.php');
            $threw = false;
            try {
                $migration->up();
            } catch (\RuntimeException $exception) {
                $threw = true;
                $this->assertStringContainsString('Reconcile non-zero subscription fee', $exception->getMessage());
            }
            $this->assertTrue($threw, 'Financial charges must be reconciled before table removal.');
            $this->assertTrue(Schema::hasTable('subscription_fees'));
            $this->assertDatabaseHas('subscription_fees', ['amount' => 175]);
        } finally {
            Schema::dropIfExists('subscription_fees');
        }
    }


    public function test_legacy_subscription_invoice_generator_is_rejected_internally(): void
    {
        $square = \App\Models\Square::create(['name' => 'مربع اختبار الإزالة']);
        $subscriber = \App\Models\Subscriber::create([
            'name' => 'مشترك اختبار',
            'square_id' => $square->id,
            'status' => 'active',
        ]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        try {
            app(\App\Services\InvoiceService::class)->generateInvoice(
                subscriber: $subscriber,
                includeReadings: false,
                invoiceType: 'subscription',
            );
        } finally {
            $this->assertDatabaseCount('invoices', 0);
        }
    }

}

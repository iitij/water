<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RetiredBillingFeatureSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_install_has_no_removed_feature_schema(): void
    {
        $this->assertFalse(Schema::hasTable('service_requests'));
        $this->assertFalse(Schema::hasColumn('invoices', 'tanker_charges'));
        $this->assertFalse(Schema::hasColumn('meters', 'tenant_name'));
        $this->assertFalse(Schema::hasColumn('meters', 'tenant_phone'));
    }

    public function test_removed_api_routes_are_absent(): void
    {
        $this->getJson('/api/service-requests')->assertNotFound();
        $this->assertContains(
            $this->postJson('/api/invoices/generate-tanker')->status(),
            [404, 405],
            'The retired invoice endpoint must not be callable.'
        );
    }

    public function test_legacy_configuration_is_removed_without_losing_unrelated_templates(): void
    {
        DB::table('settings')->insert([
            ['key' => 'inv_show_tanker', 'value' => '1'],
            ['key' => 'reading_invoice_message_template', 'value' => 'رسوم الوايت ستظهر هنا'],
            ['key' => 'subscription_invoice_template', 'value' => 'فاتورة اشتراك سليمة'],
        ]);

        $migration = require database_path('migrations/2026_11_16_000001_final_retired_billing_cleanup.php');
        $migration->up();

        $this->assertDatabaseMissing('settings', ['key' => 'inv_show_tanker']);
        $this->assertDatabaseMissing('settings', ['key' => 'reading_invoice_message_template']);
        $this->assertDatabaseHas('settings', [
            'key' => 'subscription_invoice_template',
            'value' => 'فاتورة اشتراك سليمة',
        ]);
    }

}

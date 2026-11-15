<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->postJson('/api/invoices/generate-tanker')->assertNotFound();
    }
}

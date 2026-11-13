<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One subscriber = one current meter; a separate "tenant" identity no longer
 * exists on the meter. Historical meter IDs and ledger references are retained.
 *
 * The project currently has disposable development data. Existing names/phones
 * stored only in tenant_name/tenant_phone are not migrated to subscriber fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meters', function (Blueprint $table) {
            if (Schema::hasColumn('meters', 'tenant_phone')) {
                $table->dropColumn('tenant_phone');
            }
            if (Schema::hasColumn('meters', 'tenant_name')) {
                $table->dropColumn('tenant_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meters', function (Blueprint $table) {
            if (! Schema::hasColumn('meters', 'tenant_name')) {
                $table->string('tenant_name')->nullable();
            }
            if (! Schema::hasColumn('meters', 'tenant_phone')) {
                $table->string('tenant_phone')->nullable();
            }
        });
    }
};

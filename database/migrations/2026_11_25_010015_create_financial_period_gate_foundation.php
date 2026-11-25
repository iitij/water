<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inert storage only. No seeded scope, no automatic activation, no legacy
        // postings classified as certified historical periods.
        Schema::create('financial_scopes', function (Blueprint $table) {
            $table->string('scope_key', 64)->primary();
            $table->string('currency', 3);
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('calendar_version')->default(1);
            $table->string('mode', 20)->default('LEGACY_DISABLED');
            $table->unsignedBigInteger('fence_epoch')->default(0);
            $table->uuid('active_freeze_uuid')->nullable();
        });

        Schema::create('financial_periods', function (Blueprint $table) {
            $table->id();
            $table->string('scope_key', 64);
            $table->string('month', 7);
            $table->date('start_on');
            $table->date('next_on');
            $table->string('state', 10)->default('OPEN');
            $table->unsignedBigInteger('version')->default(0);
            $table->unique(['scope_key', 'month']);
            $table->foreign('scope_key')->references('scope_key')->on('financial_scopes')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        // Never discard an initialized financial calendar by rolling back code.
        if (\Illuminate\Support\Facades\DB::table('financial_periods')->exists()
            || \Illuminate\Support\Facades\DB::table('financial_scopes')->exists()) {
            throw new \LogicException('Initialized financial period storage requires a reviewed recovery procedure.');
        }

        Schema::dropIfExists('financial_periods');
        Schema::dropIfExists('financial_scopes');
    }
};

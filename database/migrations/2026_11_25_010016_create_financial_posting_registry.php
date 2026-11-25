<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_posting_operations', function (Blueprint $table) {
            $table->uuid('operation_uuid')->primary();
            $table->foreignId('period_id')->constrained('financial_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->string('scope_key', 64);
            $table->date('effective_on');
            $table->unsignedBigInteger('fence_epoch');
            $table->unsignedBigInteger('period_version');
            $table->string('recorded_at_utc', 27);
            $table->char('payload_hash', 64);
            $table->longText('canonical_payload');
            $table->foreign('scope_key')->references('scope_key')->on('financial_scopes')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('financial_posting_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_uuid');
            $table->string('scope_key', 64);
            $table->string('source_type', 64);
            $table->string('source_id', 100);
            $table->string('leg', 64);
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->char('source_hash', 64);
            $table->unique(['scope_key', 'source_type', 'source_id', 'leg'], 'fin_event_source_leg_unique');
            $table->foreign('operation_uuid')->references('operation_uuid')->on('financial_posting_operations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('scope_key')->references('scope_key')->on('financial_scopes')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        if (DB::table('financial_posting_operations')->exists() || DB::table('financial_posting_events')->exists()) {
            throw new \LogicException('Financial posting evidence requires a reviewed recovery procedure.');
        }
        Schema::dropIfExists('financial_posting_events');
        Schema::dropIfExists('financial_posting_operations');
    }
};

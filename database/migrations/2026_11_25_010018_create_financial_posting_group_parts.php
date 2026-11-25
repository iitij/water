<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The original operation UUID and event identity remain globally unique
        // in their existing tables. No historical payload is rewritten.
        Schema::create('financial_posting_group_parts', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_uuid');
            $table->string('role', 12);
            $table->unsignedBigInteger('event_id')->unique();
            $table->foreignId('period_id')->constrained('financial_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->date('effective_on');
            $table->unsignedBigInteger('fence_epoch');
            $table->unsignedBigInteger('period_version');
            $table->unique(['operation_uuid', 'role'], 'fin_group_operation_role_unique');
            $table->index(['period_id', 'operation_uuid'], 'fin_group_period_operation_index');
            $table->foreign('operation_uuid')->references('operation_uuid')->on('financial_posting_operations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('event_id')->references('id')->on('financial_posting_events')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        if (DB::table('financial_posting_group_parts')->exists()) {
            throw new \LogicException('Financial group evidence requires a reviewed recovery procedure.');
        }
        Schema::dropIfExists('financial_posting_group_parts');
    }
};

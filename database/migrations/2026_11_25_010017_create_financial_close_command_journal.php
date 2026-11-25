<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_close_commands', function (Blueprint $table) {
            $table->uuid('command_uuid')->primary();
            $table->foreignId('period_id')->constrained('financial_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->string('action', 12);
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('result_version');
            $table->string('recorded_at_utc', 27);
            $table->char('previous_state_hash', 64);
            $table->char('command_hash', 64);
            $table->longText('command_bytes');
            $table->char('result_state_hash', 64);
            $table->longText('result_state_bytes');
            $table->unique(['period_id', 'result_version'], 'financial_close_command_version_unique');
        });
        Schema::create('financial_period_closures', function (Blueprint $table) {
            $table->unsignedBigInteger('period_id')->primary();
            $table->uuid('approval_uuid')->unique();
            $table->char('closure_hash', 64);
            $table->char('predecessor_hash', 64);
            $table->char('snapshot_hash', 64);
            $table->longText('closure_bytes');
            $table->foreign('period_id')->references('id')->on('financial_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('approval_uuid')->references('command_uuid')->on('financial_close_commands')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        if (DB::table('financial_period_closures')->exists() || DB::table('financial_close_commands')->exists()) {
            throw new \LogicException('Persisted close evidence requires a reviewed recovery procedure.');
        }
        Schema::dropIfExists('financial_period_closures');
        Schema::dropIfExists('financial_close_commands');
    }
};

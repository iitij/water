<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve the original period hints from older offline clients for strictly
 * idempotent sync retries; these hints are NEVER used as billing periods.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('readings', function (Blueprint $table): void {
            $table->date('submitted_period_start')->nullable();
            $table->date('submitted_period_end')->nullable();
        });
    }

    public function down(): void {
        Schema::table('readings', function (Blueprint $table): void {
            $table->dropColumn(['submitted_period_start', 'submitted_period_end']);
        });
    }
};

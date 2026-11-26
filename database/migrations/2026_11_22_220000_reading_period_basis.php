<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Non-destructive rollout: past calendar periods and invoices keep their
 * original meaning. Only new readings are marked observation-based.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('readings', function (Blueprint $table): void {
            $table->string('period_basis', 32)->default('legacy_calendar');
        });
    }

    public function down(): void {
        Schema::table('readings', function (Blueprint $table): void {
            $table->dropColumn('period_basis');
        });
    }
};

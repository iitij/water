<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First backfill existing NULL values
        \Illuminate\Support\Facades\DB::statement("UPDATE readings SET period_start = reading_date WHERE period_start IS NULL");
        \Illuminate\Support\Facades\DB::statement("UPDATE readings SET period_end = reading_date WHERE period_end IS NULL");

        Schema::table('readings', function (Blueprint $table) {
            $table->date('period_start')->nullable(false)->change();
            $table->date('period_end')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('readings', function (Blueprint $table) {
            $table->date('period_start')->nullable()->change();
            $table->date('period_end')->nullable()->change();
        });
    }
};

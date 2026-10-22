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
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('meter_id')->nullable()->constrained('meters')->nullOnDelete();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('meter_id')->nullable()->constrained('meters')->nullOnDelete();
        });

        Schema::table('meters', function (Blueprint $table) {
            $table->decimal('balance', 10, 2)->default(0.00)->after('initial_reading');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['meter_id']);
            $table->dropColumn('meter_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['meter_id']);
            $table->dropColumn('meter_id');
        });

        Schema::table('meters', function (Blueprint $table) {
            $table->dropColumn('balance');
        });
    }
};

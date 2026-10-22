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
        Schema::table('meters', function (Blueprint $table) {
            $table->date('installed_at')->nullable()->after('status');
            $table->date('removed_at')->nullable()->after('installed_at');
            $table->integer('initial_reading')->default(0)->after('removed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meters', function (Blueprint $table) {
            $table->dropColumn(['installed_at', 'removed_at', 'initial_reading']);
        });
    }
};

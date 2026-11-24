<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('readings', function (Blueprint $table) {
            $table->dropForeign(['meter_id']);
        });
        Schema::table('readings', function (Blueprint $table) {
            $table->unsignedBigInteger('meter_id')->nullable()->change();
            $table->foreign('meter_id')->references('id')->on('meters')->restrictOnDelete();
        });
        foreach (['invoices', 'payments', 'transactions', 'readings'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('retired_meter_id')->nullable()->index();
                $table->string('retired_meter_code', 50)->nullable();
            });
        }
    }

    public function down(): void
    {
        throw new \RuntimeException('Financial provenance cannot be dropped.');
    }
};

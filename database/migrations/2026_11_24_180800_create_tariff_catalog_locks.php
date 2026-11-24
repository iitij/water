<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tariff_catalog_locks', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedTinyInteger('marker')->default(0);
        });
        DB::table('tariff_catalog_locks')->insert(['id' => 1, 'marker' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tariff_catalog_locks');
    }
};

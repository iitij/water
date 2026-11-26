<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('readings', function (Blueprint $table) {
            $table->decimal('consumption_units', 12, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Fractional consumption cannot be safely reduced to integers.');
    }
};

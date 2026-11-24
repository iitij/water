<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('meters', function (Blueprint $table) {
            $table->decimal('initial_reading', 12, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Fractional initial readings cannot safely be reduced to integers.');
    }
};

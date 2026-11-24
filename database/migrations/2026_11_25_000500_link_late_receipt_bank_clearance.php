<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('late_receipt_reviews', function (Blueprint $table) {
            $table->string('bank_clearance_reference', 150)->nullable()->unique();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Bank clearance evidence cannot be removed by rollback.');
    }
};

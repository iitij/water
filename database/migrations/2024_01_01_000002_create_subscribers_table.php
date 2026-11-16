<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->foreignId('square_id')->constrained('squares')->cascadeOnDelete();
            $table->decimal('balance', 12, 2)->default(0)->comment('رصيد دائن');
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};

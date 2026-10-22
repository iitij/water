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
            $table->decimal('initial_subscription_fee', 12, 2)->default(0)->comment('رسوم الاشتراك الكلية');
            $table->decimal('subscription_fee_paid', 12, 2)->default(0)->comment('المسدد من رسوم الاشتراك');
            $table->boolean('is_subscription_fee_fully_paid')->default(false);
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};

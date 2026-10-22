<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meter_id')->constrained('meters')->cascadeOnDelete();
            $table->date('reading_date');
            $table->decimal('previous_reading', 12, 2)->default(0);
            $table->decimal('current_reading', 12, 2);
            $table->integer('consumption_units')->default(0)->comment('الاستهلاك بالوحدات');
            $table->boolean('is_invoiced')->default(false)->comment('هل تم فوترة هذه القراءة');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('readings');
    }
};

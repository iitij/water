<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أعطال القراءة الميدانية (لا وصول / عداد مسدود / منزل مغلق ...).
 * تُرفع من تطبيق المحصّل وتظهر للمشرف ليعيد جدولة العداد المتأخر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_faults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meter_id')->nullable()->constrained('meters')->nullOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers')->nullOnDelete();
            $table->foreignId('square_id')->nullable()->constrained('squares')->nullOnDelete();
            $table->date('reading_date');
            $table->string('fault_type', 50); // no_access, blocked, closed_house, meter_damaged, no_water, other
            $table->text('notes')->nullable();
            // لضمان عدم تكرار الرفع عند إعادة المزامنة من الجهاز
            $table->uuid('sync_uuid')->unique();
            $table->timestamp('recorded_at')->nullable(); // وقت التسجيل على الجهاز
            $table->string('device_uuid')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['reading_date', 'fault_type']);
            $table->index(['square_id', 'reading_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_faults');
    }
};

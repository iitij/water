<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * زيارات جولة المسار الميداني (Route Mode): لكل محطة زارتها أو تعذّر الوصول
 * إليها، مع المبلغ المُحصّل إن وُجد. تُرفع من الجهاز لتتبّع اكتمال الجولة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_visits', function (Blueprint $table) {
            $table->id();
            $table->date('route_date');
            $table->foreignId('meter_id')->nullable()->constrained('meters')->nullOnDelete();
            $table->foreignId('square_id')->nullable()->constrained('squares')->nullOnDelete();
            $table->string('meter_code', 100)->nullable(); // لقطة كود العداد لحماية التقرير من تغيّر/حذف العداد
            $table->string('status', 50); // visited, no_answer, refused, skipped, payment_collected
            $table->decimal('collected_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('visit_at')->nullable(); // وقت الزيارة على الجهاز
            $table->uuid('sync_uuid')->unique();
            $table->string('device_uuid')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['route_date', 'square_id']);
            $table->index(['user_id', 'route_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_visits');
    }
};

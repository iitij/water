<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل تدقيق لكل عملية مالية أو إدارية حسّاسة (خطة 2.0 — الفصل 9.6).
 * السجل لا يُحدَّث ولا يُحذف من التطبيق؛ الصفوف تُكتب مرة واحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 80);
            $table->string('entity_type', 80)->nullable();
            $table->string('entity_id', 80)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('device_id', 120)->nullable();
            $table->string('source', 20)->default('web');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['event', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};

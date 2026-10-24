<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أجهزة المستخدم: تُستخدم لإبطال جهاز مفقود ومنع إرسال عمليات معلّقة منه
 * (خطة 2.0 — الفصل 9.7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('device_uuid', 80)->unique();
            $table->string('name', 120)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};

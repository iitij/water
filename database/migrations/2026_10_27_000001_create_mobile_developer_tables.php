<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mobile_developer_settings', function (Blueprint $table) {
            $table->id();
            $table->string('pin_hash');
            $table->boolean('show_whatsapp_settings')->default(true);
            $table->boolean('allow_log_upload')->default(false);
            $table->timestamps();
        });

        Schema::create('mobile_app_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_id', 100)->nullable();
            $table->string('level', 16);
            $table->string('source', 100)->nullable();
            $table->text('message');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
            $table->index(['created_at', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_logs');
        Schema::dropIfExists('mobile_developer_settings');
    }
};

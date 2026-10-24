<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط السندات بجلسة صندوق، وإلغاء السند بدل حذفه (خطة 2.0 — الفصل 5.3).
 * الأعمدة كلها nullable حتى تبقى السندات القديمة صالحة بلا ترحيل بيانات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable()->after('user_id')
                ->constrained('cash_sessions')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('sync_uuid');
            $table->text('void_reason')->nullable()->after('voided_at');
            $table->foreignId('voided_by')->nullable()->after('void_reason')
                ->constrained('users')->nullOnDelete();
            // mobile | web | api
            $table->string('voided_via', 20)->nullable()->after('voided_by');

            $table->index(['cash_session_id', 'voided_at']);
            $table->index('voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['cash_session_id', 'voided_at']);
            $table->dropIndex(['voided_at']);
            $table->dropConstrainedForeignId('cash_session_id');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason', 'voided_via']);
        });
    }
};

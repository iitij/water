<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تسجيل نتيجة إرسال واتساب على سند القبض (خطة 2.0 — الفصل 6.2).
 *
 * المشكلة في v1: الإرسال قد ينجح أو يفشل بلا أثر، فلا يعرف المشرف ما وصل
 * للمشترك فعلاً. هنا نُخزّن وقت آخر إرسال ناجح وطريقته، ومن قام به.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('whatsapp_sent_at')->nullable()->after('void_decided_at');
            $table->string('whatsapp_sent_via', 20)->nullable()->after('whatsapp_sent_at');
            $table->foreignId('whatsapp_sent_by')->nullable()->after('whatsapp_sent_via')
                ->constrained('users')->nullOnDelete();

            // استعلام «ما أُرسل اليوم» لمتابعة المحصّلين.
            $table->index(['whatsapp_sent_at'], 'payments_whatsapp_sent_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_whatsapp_sent_idx');
            $table->dropConstrainedForeignId('whatsapp_sent_by');
            $table->dropColumn(['whatsapp_sent_at', 'whatsapp_sent_via']);
        });
    }
};

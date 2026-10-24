<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اعتماد الإلغاءات الكبيرة (خطة 2.0 — الفصل 5.3، بطاقة S4-04).
 *
 * السند الذي يتجاوز حدّ الإلغاء لا يُلغى مباشرة: يُسجَّل طلب إلغاء بسببه،
 * وينتظر قرار مشرف. لا يُحذف شيء، ويبقى السند صالحاً حتى القرار.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('void_requested_at')->nullable()->after('voided_via');
            $table->foreignId('void_requested_by')->nullable()->after('void_requested_at')
                ->constrained('users')->nullOnDelete();
            $table->text('void_request_reason')->nullable()->after('void_requested_by');
            $table->text('void_decision_notes')->nullable()->after('void_request_reason');
            $table->foreignId('void_decided_by')->nullable()->after('void_decision_notes')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('void_decided_at')->nullable()->after('void_decided_by');

            // الاستعلام الأكثر تكراراً: طلبات الإلغاء المعلّقة.
            $table->index(['void_requested_at', 'voided_at'], 'payments_void_request_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_void_request_pending_idx');
            $table->dropConstrainedForeignId('void_requested_by');
            $table->dropConstrainedForeignId('void_decided_by');
            $table->dropColumn([
                'void_requested_at',
                'void_request_reason',
                'void_decision_notes',
                'void_decided_at',
            ]);
        });
    }
};

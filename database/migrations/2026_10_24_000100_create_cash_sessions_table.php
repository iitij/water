<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جلسات صندوق المحصّل: فتح/إغلاق/اعتماد وتسوية نقدية يومية.
 * مرجع: خطة الإصدار 2.0 — الفصل 5.2 والفصل 10.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('square_id')->nullable()->constrained('squares')->nullOnDelete();
            $table->date('session_date');
            $table->decimal('opening_amount', 14, 2)->default(0);
            $table->decimal('expected_amount', 14, 2)->nullable();
            $table->decimal('counted_amount', 14, 2)->nullable();
            $table->decimal('variance_amount', 14, 2)->nullable();
            // open | closed | approved | rejected
            $table->string('state', 20)->default('open');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();
            $table->text('approval_notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'state']);
            $table->index(['session_date', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};

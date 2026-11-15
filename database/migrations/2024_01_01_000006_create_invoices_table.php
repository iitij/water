<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->string('bill_number', 50)->unique();
            $table->date('bill_date');
            $table->decimal('consumption_fee', 12, 2)->default(0)->comment('قيمة الاستهلاك');
            $table->decimal('subscription_installment', 12, 2)->default(0)->comment('قسط الاشتراك');
            $table->decimal('previous_balance', 12, 2)->default(0)->comment('المتأخرات السابقة');
            $table->decimal('credit_applied', 12, 2)->default(0)->comment('رصيد دائن مخصوم');
            $table->decimal('total_amount', 12, 2)->default(0)->comment('الإجمالي النهائي');
            $table->decimal('amount_paid', 12, 2)->default(0)->comment('المبلغ المسدد');
            $table->enum('status', ['draft', 'issued', 'partially_paid', 'paid', 'overdue'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

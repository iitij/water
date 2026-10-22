<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'invoice_type')) {
                $table->string('invoice_type')->default('meter')->after('meter_id')->comment('نوع الفاتورة: meter, subscription, tanker');
            }
        });

        // تحديث البيانات الحالية
        DB::table('invoices')->whereNotNull('meter_id')->update(['invoice_type' => 'meter']);
        
        DB::table('invoices')->whereNull('meter_id')->where('tanker_charges', '>', 0)->update(['invoice_type' => 'tanker']);
        
        DB::table('invoices')->whereNull('meter_id')->where('subscription_installment', '>', 0)->update(['invoice_type' => 'subscription']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('invoice_type');
        });
    }
};

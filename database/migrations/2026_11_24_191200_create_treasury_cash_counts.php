<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treasury_account_id')->constrained()->restrictOnDelete();
            $table->string('reference', 150)->unique();
            $table->unsignedBigInteger('counted_minor');
            $table->bigInteger('recorded_minor');
            $table->bigInteger('variance_minor');
            $table->string('ledger_hash', 64);
            $table->text('evidence');
            $table->foreignId('counted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->index(['treasury_account_id', 'observed_at']);
        });
        Schema::create('treasury_cash_count_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treasury_cash_count_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->boolean('accepted');
            $table->text('evidence');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Treasury count evidence cannot be removed by rollback.');
    }
};

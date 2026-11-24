<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->string('channel', 10);
            $table->date('opening_on');
            $table->unsignedBigInteger('opening_minor');
            $table->string('opening_reference', 150)->unique();
            $table->text('opening_evidence');
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('treasury_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treasury_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_handover_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_refund_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('kind', 30);
            $table->bigInteger('amount_minor');
            $table->date('effective_on');
            $table->string('evidence_reference', 150)->unique();
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['treasury_account_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Treasury evidence cannot be removed by migration rollback.');
    }
};

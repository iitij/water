<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_account_id')->constrained('treasury_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('treasury_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 150)->unique();
            $table->date('dispatched_on');
            $table->text('evidence');
            $table->foreignId('dispatched_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('treasury_transfer_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treasury_transfer_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 150)->unique();
            $table->date('received_on');
            $table->text('evidence');
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('treasury_postings', function (Blueprint $table) {
            $table->foreignId('treasury_transfer_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('treasury_transfer_receipt_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Treasury transfer evidence cannot be removed by rollback.');
    }
};

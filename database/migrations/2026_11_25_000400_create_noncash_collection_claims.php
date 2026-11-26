<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('noncash_collection_claims', function (Blueprint $table) {
            $table->id();
            $table->uuid('sync_uuid')->unique();
            $table->foreignId('subscriber_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('payment_method', 30);
            $table->string('instrument_reference', 150);
            $table->date('instrument_on');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_evidence')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['payment_method', 'instrument_reference'], 'noncash_claim_instrument_unique');
            $table->index(['status', 'submitted_by']);
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Noncash claim history cannot be removed by rollback.');
    }
};

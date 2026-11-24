<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_allocation_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('revision');
            $table->char('allocation_hash', 64);
            $table->unsignedBigInteger('available_minor');
            $table->unsignedBigInteger('allocated_minor');
            $table->unsignedBigInteger('unallocated_minor');
            $table->timestamp('created_at');
            $table->unique(['subscriber_id', 'revision']);
        });
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revision_id')->constrained('payment_allocation_revisions')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('account_transfer_id')->nullable()->constrained('payment_account_transfers')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->unique(['revision_id', 'position']);
            $table->index(['invoice_id', 'revision_id']);
            $table->index(['payment_id', 'revision_id']);
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Allocation history must not be destroyed by rollback.');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treasury_account_id')->constrained()->restrictOnDelete();
            $table->string('reference', 150);
            $table->date('from_date');
            $table->date('to_date');
            $table->bigInteger('opening_minor');
            $table->bigInteger('closing_minor');
            $table->string('content_hash', 64);
            $table->text('evidence');
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['treasury_account_id', 'reference']);
            $table->index(['treasury_account_id', 'from_date', 'to_date']);
        });
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained()->restrictOnDelete();
            $table->foreignId('treasury_account_id')->constrained()->restrictOnDelete();
            $table->string('bank_reference', 150);
            $table->date('booked_on');
            $table->bigInteger('amount_minor');
            $table->string('description', 500)->nullable();
            $table->timestamps();
            $table->unique(['treasury_account_id', 'bank_reference']);
        });
        Schema::create('bank_statement_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_line_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('treasury_posting_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('matched_by')->constrained('users')->restrictOnDelete();
            $table->text('evidence');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Bank reconciliation evidence cannot be removed by rollback.');
    }
};

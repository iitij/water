<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_session_fundings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('treasury_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 150)->unique();
            $table->date('issued_on');
            $table->text('evidence');
            $table->timestamps();
        });
        Schema::table('treasury_postings', function (Blueprint $table) {
            $table->foreignId('cash_session_funding_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Collector funding evidence cannot be removed by migration rollback.');
    }
};

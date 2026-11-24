<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('reference', 150)->unique();
            $table->date('received_on');
            $table->text('evidence');
            $table->timestamps();
            $table->index(['cash_session_id', 'received_on']);
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Cash handover evidence cannot be removed by rollback.');
    }
};

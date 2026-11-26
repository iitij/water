<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('treasury_held_cash_credits', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_uuid')->unique();
            $table->uuid('assignment_uuid');
            $table->foreign('assignment_uuid', 'held_credit_assignment_fk')->references('operation_uuid')->on('treasury_held_cash_assignments')->restrictOnDelete();
            $table->foreignId('subscriber_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('effective_on');
            $table->string('reference', 150)->unique();
            $table->text('evidence');
        });
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->foreignId('held_cash_credit_id')->nullable();
            $table->foreign('held_cash_credit_id', 'allocation_held_credit_fk')->references('id')->on('treasury_held_cash_credits')->restrictOnDelete();
        });
        // SQLite rebuilds the allocation table when adding a foreign key.
        // Restore append-only triggers that the rebuild may have removed.
        if (DB::getDriverName() === 'sqlite') {
            $protection = app(\App\Services\AllocationHistoryProtection::class);
            $protection->install();
            if (! $protection->active()) throw new \LogicException('Allocation history protection missing after migration.');
        }
    }

    public function down(): void
    {
        throw new \LogicException('Held cash credit and allocation evidence requires reviewed recovery.');
    }
};

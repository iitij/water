<?php

namespace Tests\Feature;

use App\Models\Meter;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use App\Services\LedgerProtection;
use App\Services\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerDatabaseProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $subscriber = Subscriber::create(['name' => 'Synthetic ledger protection', 'square_id' => Square::create(['name' => 'Protection'])->id, 'status' => 'active']);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'LEDGER-GUARD']);
        $receipt = app(PaymentService::class)->collectPayment($subscriber, 100.25, requestedMeterId: $meter->id);
        $row = DB::table('transactions')->where('reference_type', Payment::class)->where('reference_id', $receipt->id)->first();
        return [$subscriber, $meter, $row];
    }

    private function rejects(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Unsafe raw database operation succeeded.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
    }

    public function test_duplicate_reference_and_parent_deletes_are_rejected(): void
    {
        [$subscriber, $meter, $row] = $this->fixture();
        $copy = (array) $row;
        unset($copy['id']);
        $this->rejects(fn () => DB::table('transactions')->insert($copy));
        $this->rejects(fn () => DB::table('subscribers')->where('id', $subscriber->id)->delete());
        $this->rejects(fn () => DB::table('meters')->where('id', $meter->id)->delete());
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(100.25, $subscriber->getAccountBalance());
    }

    public function test_raw_update_delete_and_fake_provenance_are_rejected_when_triggers_are_present(): void
    {
        if (! app(LedgerProtection::class)->active()) {
            if (DB::getDriverName() === 'sqlite') $this->fail('Required SQLite ledger guards missing.');
            $this->artisan('ledger:protect', ['--check' => true])->assertExitCode(1);
            $this->markTestSkipped('MySQL triggers require explicit privileged installation.');
        }
        [$subscriber, $meter, $row] = $this->fixture();
        foreach ([
            ['amount' => 1],
            ['deleted_at' => now()],
            ['reference_id' => $row->reference_id + 1],
            ['meter_id' => null],
            ['meter_id' => null, 'retired_meter_id' => $meter->id, 'retired_meter_code' => 'FAKE'],
            ['meter_id' => null, 'retired_meter_id' => $meter->id, 'retired_meter_code' => $meter->unique_code, 'amount' => 1],
        ] as $change) {
            $this->rejects(fn () => DB::table('transactions')->where('id', $row->id)->update($change));
        }
        $this->rejects(fn () => DB::table('transactions')->where('id', $row->id)->delete());
        if (DB::getDriverName() === 'sqlite') {
            $this->rejects(fn () => DB::statement('INSERT OR REPLACE INTO transactions
                (id, subscriber_id, amount, type) VALUES (?, ?, ?, ?)', [$row->id, $subscriber->id, 1, 'adjustment']));
            $this->rejects(fn () => DB::statement('INSERT OR REPLACE INTO transactions
                (subscriber_id, amount, type, reference_type, reference_id) VALUES (?, ?, ?, ?, ?)',
                [$subscriber->id, 1, $row->type, $row->reference_type, $row->reference_id]));
        }
        DB::transaction(fn () => app(\App\Services\FinancialMeterRetirementService::class)->retire($meter, $subscriber));
        $this->assertDatabaseHas('transactions', ['id' => $row->id, 'meter_id' => null,
            'retired_meter_id' => $meter->id, 'retired_meter_code' => $meter->unique_code, 'amount' => '100.25']);
        $this->assertSame(100.25, $subscriber->getAccountBalance());
        $this->artisan('ledger:protect', ['--check' => true])->assertExitCode(0);
    }
}

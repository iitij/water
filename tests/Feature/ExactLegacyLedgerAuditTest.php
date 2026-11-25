<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Square;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ExactLegacyLedgerAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_accepts_exact_receipt_and_reports_mismatch_without_writes(): void
    {
        $subscriber = Square::create(['name' => 'Synthetic audit'])->subscribers()
            ->create(['name' => 'Synthetic account', 'status' => 'active']);
        $payment = app(PaymentService::class)->collectPayment($subscriber, '10.25');
        $before = DB::table('transactions')->first();
        $this->artisan('ledger:migrate')->expectsOutput('Read-only original posting audit; issues: 0. No data changed.')
            ->assertExitCode(0);
        $this->assertEquals($before, DB::table('transactions')->first());

        // Synthetic inconsistent source, not a repair or an actual ledger mutation.
        DB::table('payments')->where('id', $payment->id)->update(['amount' => '10.26']);
        $changed = DB::table('payments')->where('id', $payment->id)->first();
        $this->artisan('ledger:migrate')->expectsOutput('Read-only original posting audit; issues: 1. No data changed.')
            ->assertExitCode(1);
        $this->assertEquals($changed, DB::table('payments')->where('id', $payment->id)->first());
        $this->assertEquals($before, DB::table('transactions')->first());
    }

    public function test_audit_rejects_invalid_observed_money_instead_of_rounding_to_match(): void
    {
        $subscriber = Square::create(['name' => 'Synthetic precision'])->subscribers()
            ->create(['name' => 'Synthetic account', 'status' => 'active']);
        $payment = app(PaymentService::class)->collectPayment($subscriber, '10.25');
        $ledger = DB::table('transactions')->first();

        // SQLite DECIMAL affinity can retain malformed extra fractional digits.
        // Native MySQL DECIMAL enforces scale, so test a signed-invalid receipt
        // there without pretending it can retain a third fractional digit.
        $invalid = DB::getDriverName() === 'sqlite' ? '10.251' : '-10.25';
        DB::table('payments')->where('id', $payment->id)->update(['amount' => $invalid]);
        $before = DB::table('payments')->where('id', $payment->id)->first();
        $this->artisan('ledger:migrate')->expectsOutput('Read-only original posting audit; issues: 1. No data changed.')
            ->assertExitCode(1);
        $this->assertEquals($before, DB::table('payments')->where('id', $payment->id)->first());
        $this->assertEquals($ledger, DB::table('transactions')->first());
    }
}

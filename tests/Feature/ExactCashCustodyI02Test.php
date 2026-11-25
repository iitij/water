<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\CashHandoverService;
use App\Services\CashSessionService;
use App\Services\LateReceiptReviewService;
use App\Services\PaymentService;
use App\Services\TreasuryRegisterService;
use App\Support\CashCustodyAmounts;
use App\Support\DecimalDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ExactCashCustodyI02Test extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshSyntheticDatabase;
    }

    public function refreshDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $driver = DB::getDriverName();
        $database = DB::connection()->getDatabaseName();
        $this->assertTrue(($driver === 'sqlite' && $database === ':memory:')
            || ($driver === 'mysql' && $database === 'water_test'), 'I02 requires a dedicated synthetic database.');
        $this->refreshSyntheticDatabase();
    }

    private function collector(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function approver(): User
    {
        $user = $this->collector();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        return $user;
    }

    private function subscriber(): Subscriber
    {
        return Subscriber::create([
            'name' => 'I02 synthetic subscriber',
            'square_id' => Square::create(['name' => 'I02 synthetic square'])->id,
            'status' => 'active',
        ]);
    }

    private function funded(User $collector, User $issuer, string $amount): CashSession
    {
        $account = app(TreasuryRegisterService::class)->open($issuer, [
            'code' => 'I02-CASH', 'name' => 'I02 synthetic funding', 'channel' => 'cash',
            'opening_on' => today()->toDateString(), 'opening_amount' => '999999999999.99',
            'opening_reference' => 'I02-OPEN', 'opening_evidence' => 'Synthetic independently counted cash',
        ]);
        return app(CashSessionService::class)->openFunded($collector, $issuer, (int) $account['id'],
            $amount, 'I02-FUND', 'Synthetic actual delivery to collector');
    }

    private function expectValidation(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected a domain rejection for '.$field);
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
    }

    public function test_exact_cash_custody_at_decimal14_limit_with_partial_and_replayed_handover(): void
    {
        $collector = $this->collector();
        $issuer = $this->approver();
        $service = app(CashSessionService::class);
        $session = $this->funded($collector, $issuer, '999999999999.98');
        $this->assertSame(99999999999998, $service->expectedAmountMinor($session));
        $this->assertSame('documented', $service->fundingSummary($session)['status']);
        $session = $service->close($session, '999999999999.99');
        $this->assertSame('999999999999.98', $session->expected_amount);
        $this->assertSame('999999999999.99', $session->counted_amount);
        $this->assertSame('0.01', $session->variance_amount);
        $session = $service->decide($session, true, $issuer);
        $handover = app(CashHandoverService::class);
        $date = today()->toDateString();
        $evidence = 'I02 synthetic independently counted handover';
        $first = $handover->record($session, $issuer, '999999999999.98', 'I02-HAND-1', $date, $evidence);
        $this->assertSame('0.01', $handover->summary($session)['remaining_amount']);
        $this->assertSame('partial', $handover->summary($session)['status']);
        $this->expectValidation(fn () => $handover->record($session, $issuer, '0.02', 'I02-HAND-2', $date, $evidence), 'amount');
        $this->assertDatabaseCount('cash_handovers', 1);
        $replayed = $handover->record($session, $issuer, '999999999999.98', 'I02-HAND-1', $date, $evidence);
        $this->assertSame($first->id, $replayed->id);
        $handover->record($session, $issuer, '0.01', 'I02-HAND-3', $date, $evidence);
        $this->assertSame('complete', $handover->summary($session)['status']);
        $this->assertSame('999999999999.99', $handover->summary($session)['received_amount']);
        $this->assertDatabaseCount('cash_handovers', 2);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame('0.01', $session->fresh()->variance_amount);
    }

    public function test_actual_cash_receipts_are_summed_exactly_and_snapshot_is_immutable(): void
    {
        $collector = $this->collector();
        $service = app(CashSessionService::class);
        $session = $service->open($collector, '0e257');
        $subscriber = $this->subscriber();
        foreach (['0.10', '0.20'] as $amount) {
            $payment = app(PaymentService::class)->collectPayment($subscriber, $amount,
                userId: $collector->id, syncUuid: (string) Str::uuid());
            $this->assertSame($session->id, $payment->cash_session_id);
        }
        $this->assertSame(30, $service->expectedAmountMinor($session));
        $this->assertSame('0.30', $service->summary($session)['cash_collections']);
        $this->expectValidation(fn () => $service->close($session, '0.291'), 'counted_amount');
        $this->assertTrue($session->fresh()->isOpen());
        $session = $service->close($session, '0.29');
        $this->assertSame('0.30', $session->expected_amount);
        $this->assertSame('0.29', $session->counted_amount);
        $this->assertSame('-0.01', $session->variance_amount);
        $this->assertSame(30, $service->expectedAmountMinor($session->fresh()));
        $this->assertSame('0.30', $service->summary($session)['total_collections']);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_expected_overflow_rejects_close_without_rewriting_receipt_or_session(): void
    {
        $collector = $this->collector();
        $issuer = $this->approver();
        $service = app(CashSessionService::class);
        $session = $this->funded($collector, $issuer, '999999999999.99');
        $payment = app(PaymentService::class)->collectPayment($this->subscriber(), '0.01',
            userId: $collector->id, syncUuid: (string) Str::uuid());
        $this->assertSame($session->id, $payment->cash_session_id);
        $before = $session->fresh()->getRawOriginal();
        $receipt = $payment->fresh()->getRawOriginal();
        $audits = AuditEvent::count();
        $this->expectValidation(fn () => $service->close($session, '999999999999.99'), 'expected_amount');
        $this->assertSame($before, $session->fresh()->getRawOriginal());
        $this->assertSame($receipt, $payment->fresh()->getRawOriginal());
        $this->assertSame($audits, AuditEvent::count());
    }

    public function test_canceled_close_and_decision_audits_roll_back_state_and_snapshot(): void
    {
        $collector = $this->collector();
        $issuer = $this->approver();
        $service = app(CashSessionService::class);
        $session = $service->open($collector);
        $cancel = 'cash_session.closed';
        AuditEvent::creating(static function (AuditEvent $event) use (&$cancel) {
            return $event->event === $cancel ? false : null;
        });
        $before = $session->fresh()->getRawOriginal();
        $audits = AuditEvent::count();
        try {
            $service->close($session, '0.01');
            $this->fail('Closure proceeded without a persisted audit.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('persisted audit', $error->getMessage());
        }
        $this->assertSame($before, $session->fresh()->getRawOriginal());
        $this->assertSame($audits, AuditEvent::count());

        $cancel = 'cash_session.approved';
        $closed = $service->close($session->fresh(), '0.01');
        $before = $closed->fresh()->getRawOriginal();
        $audits = AuditEvent::count();
        try {
            $service->decide($closed, true, $issuer);
            $this->fail('Approval proceeded without a persisted audit.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('persisted audit', $error->getMessage());
        }
        $this->assertSame($before, $closed->fresh()->getRawOriginal());
        $this->assertSame($audits, AuditEvent::count());
        $cancel = '';
        $this->assertTrue($service->decide($closed->fresh(), true, $issuer)->isApproved());
    }

    public function test_late_counted_cash_capacity_is_exact_and_never_creates_second_custody_movement(): void
    {
        $collector = $this->collector();
        $issuer = $this->approver();
        $service = app(CashSessionService::class);
        $session = $service->decide($service->close($service->open($collector), '1.01'), true, $issuer);
        $subscriber = $this->subscriber();
        $late = app(LateReceiptReviewService::class);
        $submit = fn (string $amount) => $late->submit($collector, [
            'sync_uuid' => (string) Str::uuid(), 'cash_session_id' => $session->id,
            'subscriber_id' => $subscriber->id, 'amount' => $amount,
            'payment_date' => today()->toDateString(), 'payment_method' => 'cash',
        ]);
        $decision = fn (string $reference) => ['approve' => true, 'settlement_method' => 'already_counted',
            'evidence_reference' => $reference, 'evidence' => 'I02 synthetic independently verified counted cash'];
        $first = $submit('1.00');
        $late->decide($first['id'], $issuer, $decision('I02-LATE-1'));
        $this->assertSame(1, $late->countedCashAllocation($session)['available_minor']);
        $tooMuch = $submit('0.02');
        $this->expectValidation(fn () => $late->decide($tooMuch['id'], $issuer, $decision('I02-LATE-2')), 'amount');
        $this->assertDatabaseHas('late_receipt_reviews', ['id' => $tooMuch['id'], 'status' => 'pending']);
        $this->assertDatabaseCount('payments', 1);
        $last = $submit('0.01');
        $late->decide($last['id'], $issuer, $decision('I02-LATE-3'));
        $this->assertSame(101, $late->countedCashAllocation($session)['allocated_minor']);
        $this->assertSame(0, $late->countedCashAllocation($session)['available_minor']);
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(0, Payment::query()->whereNotNull('cash_session_id')->count());
        $this->assertDatabaseCount('cash_handovers', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertSame('1.01', $session->fresh()->variance_amount);
    }
}

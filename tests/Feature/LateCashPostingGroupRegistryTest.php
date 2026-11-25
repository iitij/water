<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use App\Models\User;
use App\Models\AuditEvent;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryTransferService;
use App\Services\TreasuryTransferEvidence;
use App\Services\GatedCashReceiptWriter;
use App\Services\PaymentService;
use App\Models\Subscriber;
use App\Models\Square;
use App\Models\Payment;
use App\Services\LateCashPostingGroupRegistry;
use App\Services\LateReceiptReviewService;
use App\Services\GatedTreasuryOpeningFundingWriter;
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LateCashPostingGroupRegistryTest extends TestCase
{
    private const UUID = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || ! (
            (DB::getDriverName() === 'sqlite' && DB::getDatabaseName() === ':memory:')
            || (DB::getDriverName() === 'mysql' && DB::getDatabaseName() === 'water_test')
        )) {
            $this->markTestSkipped('Requires disposable isolated ledger evidence database.');
        }
        $this->travelTo(\Carbon\Carbon::instance($this->nowAt()));
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        DB::table('financial_scopes')->insert([
            'scope_key' => 'water', 'currency' => 'YER', 'timezone' => 'Asia/Aden',
            'mode' => 'ACTIVE', 'calendar_version' => 1, 'fence_epoch' => 5,
        ]);
        DB::table('financial_periods')->insert([
            'scope_key' => 'water', 'month' => '2026-11', 'start_on' => '2026-11-01',
            'next_on' => '2026-12-01', 'state' => 'OPEN', 'version' => 2,
        ]);
    }

    private function nowAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-25T08:30:00.123456Z');
    }

    private function gate(callable $writer): mixed
    {
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', '2026-11-25', $this->nowAt(), $writer);
    }

    private function actor(): User
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $actor;
    }

    private const EVIDENCE = 'Independent verified separate physical cash receipt';

    private function fixture(bool $registered = true, string $day = '2026-10-31',
        string $counted = '0.00', bool $approved = true): array
    {
        DB::table('financial_periods')->insert(['scope_key' => 'water', 'month' => '2026-10',
            'start_on' => '2026-10-01', 'next_on' => '2026-11-01', 'state' => 'OPEN', 'version' => 7]);
        $collector = $this->actor();
        $reviewer = $this->actor();
        $subscriber = Subscriber::create(['name' => 'Synthetic late cash account',
            'square_id' => Square::create(['name' => 'Synthetic late cash square'])->id, 'status' => 'active']);
        $data = ['code' => 'QA-LATE-CASH', 'name' => 'Synthetic cash account', 'channel' => 'cash',
            'opening_on' => '2026-11-24', 'opening_amount' => '0.00',
            'opening_reference' => 'QA-LATE-OPEN', 'opening_evidence' => 'Independent original opening evidence'];
        $account = $registered
            ? app(GatedTreasuryOpeningFundingWriter::class)->open($reviewer, $data, self::UUID, $this->nowAt())['account']
            : app(TreasuryRegisterService::class)->open($reviewer, $data);
        $this->travelTo(\Carbon\Carbon::parse($day.'T06:00:00Z'));
        $sessions = app(CashSessionService::class);
        $session = $sessions->close($sessions->open($collector), $counted);
        if ($approved) $session = $sessions->decide($session, true, $reviewer);
        $this->travelTo(\Carbon\Carbon::instance($this->nowAt()));
        $review = app(LateReceiptReviewService::class)->submit($collector, [
            'sync_uuid' => self::OTHER, 'cash_session_id' => $session->id, 'subscriber_id' => $subscriber->id,
            'payment_method' => 'cash', 'amount' => '10.25', 'payment_date' => $day,
        ]);
        return [$collector, $reviewer, $subscriber, $account, $session->fresh(), $review];
    }

    private function both(callable $callback): mixed
    {
        return app(FinancialWriteGate::class)->withinOpenPeriods('water',
            ['2026-10-31', '2026-11-25'], $this->nowAt(), $callback);
    }

    private function post(User $actor, array $review, array $account): array
    {
        // Isolated persistence fixture, not a production W17 adapter.
        return $this->both(function (array $contexts) use ($actor, $review, $account): array {
            $result = app(LateReceiptReviewService::class)->receiveCash((int) $review['id'], $actor,
                (int) $account['id'], '2026-11-25', 'QA-GROUP-RECEIPT', self::EVIDENCE);
            return $result + ['registry_created' => app(LateCashPostingGroupRegistry::class)
                ->record((int) $review['id'], $contexts, $this->nowAt())];
        });
    }

    private function rejects(string $reason, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected rejection.');
        } catch (DomainException $error) {
            $this->assertSame($reason, $error->getMessage());
        }
    }

    public function test_group_persists_both_actual_dates_and_replays_without_mutating_format_one(): void
    {
        [, $reviewer, $subscriber, $account, $session, $review] = $this->fixture();
        $opening = (array) DB::table('financial_posting_operations')->where('operation_uuid', self::UUID)->sole();
        $till = $session->getRawOriginal();
        $result = $this->post($reviewer, $review, $account);
        $this->assertTrue($result['registry_created']);
        $this->assertSame('2026-10-31', Payment::findOrFail($result['review']['payment_id'])->payment_date->toDateString());
        $this->assertSame('2026-11-25', $result['posting']['effective_on']);
        $this->assertDatabaseHas('financial_posting_operations', ['operation_uuid' => self::OTHER, 'payload_format' => 2]);
        $parts = DB::table('financial_posting_group_parts')->where('operation_uuid', self::OTHER)->orderBy('effective_on')->get();
        $this->assertSame(['2026-10-31', '2026-11-25'], $parts->pluck('effective_on')->all());
        $this->assertSame(['subscriber', 'treasury'], $parts->pluck('role')->all());
        $this->assertNotSame((int) $parts[0]->period_id, (int) $parts[1]->period_id);
        $this->assertFalse($this->post($reviewer, $review, $account)['registry_created']);
        $this->assertSame($opening, (array) DB::table('financial_posting_operations')->where('operation_uuid', self::UUID)->sole());
        $this->assertSame($till, $session->fresh()->getRawOriginal());
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('financial_posting_operations', 2);
        $this->assertDatabaseCount('financial_posting_events', 3);
        $this->assertDatabaseCount('financial_posting_group_parts', 2);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
    }

    public function test_old_verifier_holds_both_periods_and_single_date_replay_is_rejected(): void
    {
        [, $reviewer, , $account, , $review] = $this->fixture();
        $r = $this->post($reviewer, $review, $account);
        $this->both(function (array $contexts) use ($r): void {
            $registry = app(FinancialPostingRegistry::class);
            foreach ($contexts as $context) {
                $this->rejects('posting_group_verifier_required', fn () =>
                    $registry->verifyPeriod('water', $context['period_id']));
            }
            $leg = app(\App\Services\TreasuryCollectionEvidence::class)->leg((int) $r['posting']['id']);
            $this->rejects('posting_group_requires_group_registry', fn () =>
                $registry->record($contexts['2026-11-25'], self::OTHER, [$leg], $this->nowAt()));
        });
        $this->assertDatabaseCount('financial_posting_events', 3);
    }

    public function test_missing_part_rejected_without_recreating_evidence(): void
    {
        [, $reviewer, , $account, , $review] = $this->fixture();
        $this->post($reviewer, $review, $account);
        DB::table('financial_posting_group_parts')->where('role', 'treasury')->delete();
        $this->rejects('late_cash_group_parts_corrupt', fn () => $this->post($reviewer, $review, $account));
        $this->assertDatabaseCount('financial_posting_group_parts', 1);
        $this->assertDatabaseCount('financial_posting_events', 3);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_closed_original_period_blocks_business_effects(): void
    {
        [, $reviewer, $subscriber, $account, , $review] = $this->fixture();
        DB::table('financial_periods')->where('month', '2026-10')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', fn () => $this->post($reviewer, $review, $account));
        $this->assertSame(0, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseHas('late_receipt_reviews', ['id' => $review['id'], 'status' => 'pending']);
        $this->assertDatabaseCount('financial_posting_group_parts', 0);
        $this->assertDatabaseCount('financial_posting_events', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
    }
    private function verifyGroup(string $month): array
    {
        $id = (int) DB::table('financial_periods')->where('month', $month)->value('id');
        return DB::transaction(fn () => app(\App\Services\LateCashPostingGroupVerifier::class)
            ->verifyPeriod('water', $id));
    }

    public function test_historical_group_counts_only_its_own_effect_in_each_period(): void
    {
        [, $reviewer, , $account, , $review] = $this->fixture();
        $this->post($reviewer, $review, $account);
        $october = $this->verifyGroup('2026-10');
        $november = $this->verifyGroup('2026-11');
        foreach ([$october, $november] as $result) {
            $this->assertSame(1, $result['group_operations']);
            $this->assertSame(1, $result['group_events']);
            $this->assertFalse($result['source_coverage_verified']);
            $this->assertFalse($result['closure_authorized']);
        }
        $this->assertNotSame($october['group_registry_hash'], $november['group_registry_hash']);
        DB::table('financial_periods')->where('month', '2026-10')->update(['state' => 'CLOSED', 'version' => 8]);
        DB::table('financial_scopes')->where('scope_key', 'water')->update(['fence_epoch' => 6]);
        $this->assertSame($october, $this->verifyGroup('2026-10'));
        $this->assertSame($november, $this->verifyGroup('2026-11'));
    }

    public function test_missing_non_anchor_part_blocks_verification_even_for_non_anchor_period(): void
    {
        [, $reviewer, , $account, , $review] = $this->fixture();
        $this->post($reviewer, $review, $account);
        DB::table('financial_posting_group_parts')->where('role', 'treasury')->delete();
        foreach (['2026-10', '2026-11'] as $month) {
            $this->rejects('late_cash_group_verifier_parts', fn () => $this->verifyGroup($month));
        }
    }

    public function test_event_hash_and_rehashed_payload_cannot_replace_actual_source_proof(): void
    {
        [, $reviewer, , $account, , $review] = $this->fixture();
        $this->post($reviewer, $review, $account);
        $row = DB::table('financial_posting_operations')->where('operation_uuid', self::OTHER)->sole();
        $payload = json_decode($row->canonical_payload, true, 512, JSON_THROW_ON_ERROR);
        $fake = str_repeat('a', 64);
        $payload['parts'][1]['leg']['source_hash'] = $fake;
        $bytes = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        DB::table('financial_posting_operations')->where('operation_uuid', self::OTHER)
            ->update(['canonical_payload' => $bytes, 'payload_hash' => hash('sha256', $bytes)]);
        DB::table('financial_posting_events')->where('operation_uuid', self::OTHER)->where('leg', 'treasury')
            ->update(['source_hash' => $fake]);
        foreach (['2026-10', '2026-11'] as $month) {
            $this->rejects('late_cash_group_proof_mismatch', fn () => $this->verifyGroup($month));
        }
    }

    public function test_future_original_version_is_rejected_by_historical_verifier(): void
    {
        [, $reviewer, , $account, , $review] = $this->fixture();
        $this->post($reviewer, $review, $account);
        DB::table('financial_posting_group_parts')->where('role', 'treasury')->update(['period_version' => 999]);
        $this->rejects('late_cash_group_context', fn () => $this->verifyGroup('2026-10'));
    }

}

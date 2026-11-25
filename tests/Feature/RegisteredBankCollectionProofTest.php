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
use App\Models\Transaction;
use App\Services\RegisteredBankCollectionProof;
use App\Services\FinancialLedgerEvidence;
use App\Services\TreasuryCollectionEvidence;
use App\Services\GatedTreasuryOpeningFundingWriter;
use App\Services\NonCashClaimService;
use App\Services\BankReconciliationService;
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RegisteredBankCollectionProofTest extends TestCase
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

    private const BANK_ONLY = '33333333-3333-4333-8333-333333333333';
    private const EVIDENCE = 'Independent proof of actual bank collection';

    private function fixture(string $mode = 'paired', string $method = 'check', bool $opening = true): array
    {
        $submitter = $this->actor();
        $importer = $this->actor();
        $reviewer = $this->actor();
        $subscriber = Subscriber::create(['name' => 'Synthetic collection proof account',
            'square_id' => Square::create(['name' => 'Synthetic proof square'])->id, 'status' => 'active']);
        $data = ['code' => 'QA-PAIR', 'name' => 'Synthetic bank', 'channel' => 'bank',
            'opening_on' => '2026-11-24', 'opening_amount' => '0.00', 'opening_reference' => 'QA-PAIR-OPEN',
            'opening_evidence' => 'Independent opening bank statement evidence'];
        $account = $opening
            ? app(GatedTreasuryOpeningFundingWriter::class)->open($importer, $data, self::UUID, $this->nowAt())['account']
            : app(TreasuryRegisterService::class)->open($importer, $data);
        $claim = app(NonCashClaimService::class)->submit($submitter, [
            'sync_uuid' => self::OTHER, 'subscriber_id' => $subscriber->id, 'payment_method' => $method,
            'amount' => '10.25', 'instrument_reference' => 'QA-PAIR-INSTRUMENT', 'instrument_on' => '2026-11-24',
        ]);
        $report = app(BankReconciliationService::class)->import($importer, (int) $account['id'], [
            'reference' => 'QA-PAIR-STATEMENT', 'from_date' => '2026-11-25', 'to_date' => '2026-11-25',
            'opening_amount' => '0.00', 'closing_amount' => '10.25',
            'evidence' => 'Original synthetic bank statement',
            'lines' => [['bank_reference' => 'QA-PAIR-CREDIT', 'booked_on' => '2026-11-25', 'amount' => '10.25']],
        ]);
        $line = DB::table('bank_statement_lines')->where('bank_statement_id', $report['statement']['id'])->sole();
        // Actual isolated business service, independent of unaccepted W15/W16
        // adapters. Explicit fixture registration tests verification only.
        $result = $this->gate(function (array $context) use ($reviewer, $claim, $report, $line, $mode): array {
            $r = app(PaymentService::class)->clearNonCashClaim($reviewer, (int) $claim['id'],
                (int) $report['statement']['id'], (int) $line->id, 'QA-PAIR-CLEARANCE', self::EVIDENCE);
            $entry = Transaction::query()->where('reference_type', Payment::class)
                ->where('reference_id', $r['payment_id'])->where('type', 'payment')->sole();
            $ledger = app(FinancialLedgerEvidence::class)->leg((int) $entry->id);
            $bank = app(TreasuryCollectionEvidence::class)->leg((int) $r['bank']['posting']['id']);
            $registry = app(FinancialPostingRegistry::class);
            if ($mode === 'paired') $registry->record($context, self::OTHER, [$ledger, $bank], $this->nowAt());
            if ($mode === 'ledger_only') $registry->record($context, self::OTHER, [$ledger], $this->nowAt());
            if ($mode === 'split') {
                $registry->record($context, self::OTHER, [$ledger], $this->nowAt());
                $registry->record($context, self::BANK_ONLY, [$bank], $this->nowAt());
            }
            return $r;
        });
        return [$subscriber, (int) $result['payment_id'], $result['bank']['posting']];
    }

    private function verify(int $id): array
    {
        return $this->gate(fn () => app(RegisteredBankCollectionProof::class)->verify($id));
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

    public function test_actual_registered_pair_is_verified_without_new_posting(): void
    {
        [$subscriber, $id, $posting] = $this->fixture();
        $audit = AuditEvent::query()->count();
        $receipt = Payment::findOrFail($id)->getRawOriginal();
        $proof = $this->verify($id);
        $this->assertTrue($proof['pair_verified']);
        $this->assertFalse($proof['source_coverage_verified']);
        $this->assertFalse($proof['certified_balance']);
        $this->assertSame(1025, $proof['amount_minor']);
        $this->assertSame(self::OTHER, $proof['operation_uuid']);
        $this->assertSame((int) $posting['id'], $proof['posting_id']);
        $this->assertSame('2026-11-25', $proof['effective_on']);
        $this->assertSame($proof, $this->verify($id));
        $this->assertSame($receipt, Payment::findOrFail($id)->getRawOriginal());
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame($audit, AuditEvent::query()->count());
        foreach (['payments', 'transactions', 'treasury_bank_collections', 'treasury_postings', 'bank_statement_matches'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseCount('financial_posting_events', 3);
    }

    public function test_transfer_pair_is_verified(): void
    {
        [, $id] = $this->fixture(method: 'bank_transfer');
        $this->assertTrue($this->verify($id)['pair_verified']);
    }

    public function test_missing_bank_registry_leg_is_not_repaired_by_read_only_proof(): void
    {
        [$subscriber, $id] = $this->fixture('ledger_only');
        $this->rejects('bank_pair_unregistered_effect', fn () => $this->verify($id));
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertDatabaseCount('treasury_postings', 1);
    }

    public function test_separately_registered_legs_are_not_a_single_atomic_operation(): void
    {
        [, $id] = $this->fixture('split');
        $this->rejects('bank_pair_operation_mismatch', fn () => $this->verify($id));
        $this->assertDatabaseCount('financial_posting_operations', 3);
        $this->assertDatabaseCount('financial_posting_events', 3);
    }

    public function test_legacy_clearance_is_not_adopted(): void
    {
        [, $id] = $this->fixture('legacy');
        $this->rejects('bank_pair_unregistered_effect', fn () => $this->verify($id));
        $this->assertDatabaseCount('financial_posting_events', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_unregistered_opening_is_not_certified(): void
    {
        [, $id] = $this->fixture(opening: false);
        $this->rejects('bank_pair_unregistered_effect', fn () => $this->verify($id));
        $this->assertDatabaseCount('financial_posting_events', 2);
    }

    public function test_date_tamper_is_rejected_even_if_subscriber_amount_unchanged(): void
    {
        [, $id] = $this->fixture();
        DB::table('payments')->where('id', $id)->update(['payment_date' => '2026-11-24']);
        $this->rejects('bank_pair_date_mismatch', fn () => $this->verify($id));
        $this->assertDatabaseCount('financial_posting_events', 3);
    }

    public function test_tampered_audit_evidence_rejected_even_with_unchanged_registered_legs(): void
    {
        [, $id] = $this->fixture();
        $this->assertTrue($this->verify($id)['pair_verified']);
        $cases = [
            ['bank_statement.imported', 'line_count', 2, 'bank_pair_import_audit_mismatch'],
            ['treasury.bank_collection_confirmed', 'subscriber_posting_created', true, 'bank_pair_confirmation_audit_mismatch'],
            ['noncash_claim.submitted', 'instrument_on', '2026-11-23', 'bank_pair_submission_audit_mismatch'],
            ['noncash_claim.cleared', 'decision_evidence', 'Changed approval evidence', 'bank_pair_decision_audit_mismatch'],
        ];
        $events = DB::table('financial_posting_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        foreach ($cases as [$event, $field, $value, $reason]) {
            $audit = AuditEvent::query()->where('event', $event)->sole();
            $after = $audit->after;
            $changed = $after;
            $changed[$field] = $value;
            DB::table('audit_events')->where('id', $audit->id)->update(['after' => json_encode($changed, JSON_THROW_ON_ERROR)]);
            $this->rejects($reason, fn () => $this->verify($id));
            DB::table('audit_events')->where('id', $audit->id)->update(['after' => json_encode($after, JSON_THROW_ON_ERROR)]);
            $this->assertTrue($this->verify($id)['pair_verified']);
        }
        $this->assertSame($events, DB::table('financial_posting_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
    }

    public function test_missing_required_audit_rejected_without_creating_replacement(): void
    {
        [, $id] = $this->fixture();
        $audit = AuditEvent::query()->where('event', 'noncash_claim.cleared')->sole();
        DB::table('audit_events')->where('id', $audit->id)->delete();
        $this->rejects('bank_pair_audit_missing_or_duplicate', fn () => $this->verify($id));
        $this->assertSame(0, AuditEvent::query()->where('event', 'noncash_claim.cleared')->count());
        $this->assertDatabaseCount('financial_posting_events', 3);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
    }

    public function test_requires_transaction_and_rejects_ordinary_cash_receipt(): void
    {
        $this->rejects('bank_pair_requires_outer_transaction',
            fn () => app(RegisteredBankCollectionProof::class)->verify(1));
        $actor = $this->actor();
        $subscriber = Subscriber::create(['name' => 'Synthetic cash',
            'square_id' => Square::create(['name' => 'Synthetic cash square'])->id, 'status' => 'active']);
        $result = app(GatedCashReceiptWriter::class)->collect($actor, $subscriber->id, '10.25', self::UUID, $this->nowAt());
        $this->rejects('bank_pair_receipt_unsupported', fn () => $this->verify((int) $result['payment']->id));
        $this->assertDatabaseCount('treasury_postings', 0);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * اختبارات أساس الإصدار 2.0: صندوق المحصّل، إلغاء السند، تدوير التوكن،
 * وإبطال الأجهزة. مرجع: docs/V2_MOBILE_PLAN_AR.md — الدورتان S1 وS2.
 */
class CashSessionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Square $square;

    private Subscriber $subscriber;

    private Meter $meter;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'collector', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole('super_admin');

        Tariff::create([
            'name' => 'الشريحة الأساسية',
            'unit_price' => 300,
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
        ]);

        $this->square = Square::create(['name' => 'المربع الأول']);
        $this->subscriber = Subscriber::create([
            'name' => 'مشترك صندوق',
            'square_id' => $this->square->id,
            'status' => 'active',
            'phone' => '777000111',
        ]);
        $this->meter = Meter::create([
            'unique_code' => 'CS-MTR-1',
            'path_number' => 3,
            'subscriber_id' => $this->subscriber->id,
            'initial_reading' => 0,
        ]);
        $this->invoice = Invoice::create([
            'subscriber_id' => $this->subscriber->id,
            'meter_id' => $this->meter->id,
            'bill_number' => 'INV-CS-1',
            'bill_date' => now()->toDateString(),
            'consumption_fee' => 1000,
            'total_amount' => 1000,
            'amount_paid' => 0,
            'status' => 'issued',
        ]);
    }

    private function fundedSession(\App\Models\User $collector, string $amount): \App\Models\CashSession
    {
        $issuer = \App\Models\User::factory()->create(['role' => 'admin']);
        $issuer->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $key = substr((string) \Illuminate\Support\Str::uuid(), 0, 12);
        $account = app(\App\Services\TreasuryRegisterService::class)->open($issuer, [
            'code' => 'CASH-'.$key, 'name' => 'Synthetic cash funding', 'channel' => 'cash',
            'opening_on' => today()->toDateString(), 'opening_amount' => '10000',
            'opening_reference' => 'OPEN-'.$key, 'opening_evidence' => 'Synthetic independently counted opening',
        ]);
        return app(\App\Services\CashSessionService::class)->openFunded($collector, $issuer, (int) $account['id'],
            $amount, 'FUND-'.$key, 'Synthetic actual handover to collector');
    }

    public function test_cash_session_lifecycle_with_payments_and_variance(): void
    {
        $open = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/cash-sessions', ['opening_amount' => 0])
            ->assertCreated()
            ->assertJsonPath('cash_session.state', 'open');

        $sessionId = $open->json('cash_session.id');

        // لا يُسمح بصندوقين مفتوحين لنفس المستخدم.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/cash-sessions', [])
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/invoices/{$this->invoice->id}/pay", [
                'amount' => 1000,
                'payment_method' => 'cash',
                'sync_uuid' => (string) Str::uuid(),
            ])
            ->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();
        $this->assertSame($sessionId, $payment->cash_session_id);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/cash-sessions/current')
            ->assertOk()
            ->assertJsonPath('cash_session.expected_amount', '1000.00')
            ->assertJsonPath('cash_session.receipts', 1);

        // فرق 50 ر.ي ناقص.
        $closed = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/cash-sessions/{$sessionId}/close", ['counted_amount' => 950])
            ->assertOk()
            ->assertJsonPath('cash_session.state', 'closed')
            ->assertJsonPath('cash_session.variance_amount', '-50.00');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/cash-sessions/{$sessionId}/approve", [
                'approve' => true,
                'notes' => 'فرق مبرر ومُعتمد.',
            ])
            ->assertOk()
            ->assertJsonPath('cash_session.state', 'approved');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/cash-sessions/{$sessionId}/report")
            ->assertOk()
            ->assertJsonPath('payments.0.voided', false)
            ->assertJsonStructure(['cash_session', 'payments', 'generated_at']);
    }

    public function test_payment_replay_after_cash_session_close_never_posts_again_or_changes_identity(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $sessionId = $this->postJson('/api/cash-sessions', ['opening_amount' => 0])
            ->assertCreated()->json('cash_session.id');
        $uuid = 'abcdef12-3456-4789-abcd-ef1234567890';
        $payload = ['amount' => '600.25', 'payment_method' => 'cash',
            'payment_date' => today()->toDateString(), 'reference_number' => 'OFFLINE-1',
            'sync_uuid' => strtoupper($uuid)];
        $endpoint = "/api/invoices/{$this->invoice->id}/pay";
        $id = $this->postJson($endpoint, $payload)->assertCreated()->json('payment.id');
        $this->postJson("/api/cash-sessions/{$sessionId}/close", ['counted_amount' => 600.25])
            ->assertOk()->assertJsonPath('cash_session.expected_amount', '600.25');
        $newId = $this->postJson('/api/cash-sessions', [])->assertCreated()->json('cash_session.id');

        $payload['sync_uuid'] = $uuid;
        $this->postJson($endpoint, $payload)->assertSuccessful()->assertJsonPath('payment.id', $id);
        foreach ([
            ['amount' => '600.26'],
            ['payment_date' => today()->subDay()->toDateString()],
            ['reference_number' => 'OFFLINE-2'],
            ['payment_method' => 'bank_transfer'],
            ['reason' => 'Changed financial reason'],
        ] as $change) {
            $this->postJson($endpoint, array_replace($payload, $change))->assertConflict();
        }

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['id' => $id, 'sync_uuid' => $uuid, 'cash_session_id' => $sessionId]);
        $this->assertDatabaseMissing('payments', ['cash_session_id' => $newId]);
        $this->assertDatabaseHas('cash_sessions', ['id' => $sessionId, 'expected_amount' => 600.25, 'state' => 'closed']);
        $this->assertSame('600.25', $this->invoice->fresh()->amount_paid);
    }

    public function test_physical_cash_settlement_includes_opening_and_excludes_non_cash_methods(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $sessionId = $this->fundedSession($this->admin, '250.25')->id;
        $this->getJson('/api/cash-sessions/current')->assertOk()->assertJsonPath('cash_session.expected_amount', '250.25');
        $endpoint = "/api/invoices/{$this->invoice->id}/pay";
        $this->postJson($endpoint, ['amount' => '600.50', 'payment_method' => 'cash',
            'sync_uuid' => (string) Str::uuid()])->assertCreated();
        foreach (['bank_transfer' => '300.00', 'check' => '99.50'] as $method => $amount) {
            $this->postJson($endpoint, ['amount' => $amount, 'payment_method' => $method,
                'sync_uuid' => (string) Str::uuid()])->assertUnprocessable();
            // Historical receipts remain in original session reporting, but cannot
            // be issued through the old noncash path after the new guard.
            $legacy = \App\Models\Payment::create(['subscriber_id' => $this->invoice->subscriber_id,
                'meter_id' => $this->invoice->meter_id, 'invoice_id' => $this->invoice->id,
                'user_id' => $this->admin->id, 'cash_session_id' => $sessionId,
                'receipt_number' => 'LEGACY-'.$method, 'amount' => $amount, 'payment_date' => today(),
                'payment_method' => $method, 'reference_number' => 'LEGACY-'.$method]);
            app(\App\Services\TransactionService::class)->recordTransaction($this->invoice->subscriber, 'payment',
                (float) $amount, 'Historical noncash receipt', $legacy, $this->invoice->meter);
            app(\App\Services\PaymentService::class)->recalculateInvoices($this->invoice->subscriber);
        }
        $this->getJson('/api/cash-sessions/current')->assertOk()
            ->assertJsonPath('cash_session.settlement_basis', 'physical_cash')
            ->assertJsonPath('cash_session.cash_collections', '600.50')
            ->assertJsonPath('cash_session.non_cash_collections', '399.50')
            ->assertJsonPath('cash_session.total_collections', '1000.00')
            ->assertJsonPath('cash_session.expected_amount', '850.75');
        $this->postJson("/api/cash-sessions/{$sessionId}/close", ['counted_amount' => '850.751'])
            ->assertUnprocessable()->assertJsonValidationErrors('counted_amount');
        $this->postJson("/api/cash-sessions/{$sessionId}/close", ['counted_amount' => '850.75'])
            ->assertOk()->assertJsonPath('cash_session.variance_amount', '0.00')
            ->assertJsonPath('cash_session.expected_amount', '850.75');
        $this->assertDatabaseCount('payments', 3);
        $this->assertSame('1000.00', $this->invoice->fresh()->amount_paid);
        $this->getJson("/api/cash-sessions/{$sessionId}/report")->assertOk()
            ->assertJsonPath('cash_session.total_collections', '1000.00')
            ->assertJsonPath('cash_session.expected_amount', '850.75');
    }

    public function test_opening_cash_and_counted_cash_reject_excess_precision(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/cash-sessions', ['opening_amount' => '1.001'])
            ->assertUnprocessable()->assertJsonValidationErrors('opening_amount');
        $this->assertDatabaseCount('cash_sessions', 0);
    }

    public function test_deferred_receipt_never_moves_to_a_new_cash_session(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $old = $this->postJson('/api/cash-sessions', [])->assertCreated()->json('cash_session.id');
        $endpoint = "/api/invoices/{$this->invoice->id}/pay";
        $payload = ['amount' => '100.25', 'payment_method' => 'cash',
            'payment_date' => today()->toDateString(), 'cash_session_id' => $old,
            'sync_uuid' => (string) Str::uuid(), 'deferred_sync' => true];
        $id = $this->postJson($endpoint, $payload)->assertCreated()->json('payment.id');
        $this->postJson("/api/cash-sessions/{$old}/close", ['counted_amount' => '100.25'])
            ->assertOk()->assertJsonPath('cash_session.expected_amount', '100.25');
        $new = $this->postJson('/api/cash-sessions', [])->assertCreated()->json('cash_session.id');
        $this->postJson($endpoint, $payload)->assertSuccessful()->assertJsonPath('payment.id', $id);
        $late = array_replace($payload, ['sync_uuid' => (string) Str::uuid()]);
        $this->postJson($endpoint, $late)->assertConflict();
        $this->postJson('/api/payments', $late + ['subscriber_id' => $this->subscriber->id,
            'invoice_id' => $this->invoice->id])->assertConflict();
        $this->postJson($endpoint, array_replace($late, ['cash_session_id' => null]))->assertConflict();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseMissing('payments', ['cash_session_id' => $new]);
        $this->assertDatabaseHas('cash_sessions', ['id' => $old, 'expected_amount' => '100.25']);
        $this->assertSame('100.25', $this->invoice->fresh()->amount_paid);
    }

    public function test_late_receipt_review_requires_independent_verified_handover_and_posts_once(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $old = $this->postJson('/api/cash-sessions', [])->assertCreated()->json('cash_session.id');
        $this->postJson("/api/cash-sessions/{$old}/close", ['counted_amount' => 0])->assertOk();
        $supervisor = User::factory()->create(['role' => 'admin']);
        $supervisor->assignRole('super_admin');
        $this->actingAs($supervisor, 'sanctum')->postJson("/api/cash-sessions/{$old}/approve", ['approve' => true])->assertOk();
        $this->actingAs($this->admin, 'sanctum');
        $new = $this->postJson('/api/cash-sessions', [])->assertCreated()->json('cash_session.id');
        $payload = ['subscriber_id' => $this->subscriber->id, 'invoice_id' => $this->invoice->id,
            'cash_session_id' => $old, 'sync_uuid' => (string) Str::uuid(),
            'amount' => '125.25', 'payment_method' => 'cash', 'payment_date' => today()->toDateString()];
        $case = $this->postJson('/api/late-receipt-reviews', $payload)->assertSuccessful()->json('review.id');
        $this->postJson('/api/late-receipt-reviews', $payload)->assertSuccessful()->assertJsonPath('review.id', $case);
        $this->postJson('/api/late-receipt-reviews', array_replace($payload, ['amount' => '126.25']))->assertConflict();
        $decision = ['approve' => true, 'evidence' => 'Verified separate treasury handover after till closure',
            'evidence_reference' => 'TREASURY-LATE-001', 'settlement_method' => 'separate_handover'];
        $this->postJson("/api/late-receipt-reviews/{$case}/decide", $decision)->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
        $this->actingAs($supervisor, 'sanctum');
        $this->postJson("/api/late-receipt-reviews/{$case}/decide", $decision)->assertUnprocessable();
        $account = app(\App\Services\TreasuryRegisterService::class)->open($supervisor, [
            'code' => 'LATE-CASH', 'name' => 'Synthetic late cash treasury', 'channel' => 'cash',
            'opening_on' => today()->toDateString(), 'opening_amount' => '0', 'opening_reference' => 'LATE-CASH-OPEN',
            'opening_evidence' => 'Independent physical opening count',
        ]);
        $clear = ['treasury_account_id' => $account['id'], 'received_on' => today()->toDateString(),
            'evidence_reference' => $decision['evidence_reference'], 'evidence' => $decision['evidence']];
        $clearUrl = "/api/late-receipt-reviews/{$case}/clear-cash";
        $this->postJson($clearUrl, $clear + ['amount' => '1.00'])->assertUnprocessable();
        $this->postJson($clearUrl, array_replace($clear, ['received_on' => today()->subDay()->toDateString()]))->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $id = $this->postJson($clearUrl, $clear)->assertOk()->assertJsonPath('review.status', 'approved')
            ->assertJsonPath('posting.amount_minor', 12525)->json('review.payment_id');
        $this->postJson($clearUrl, $clear)->assertOk()->assertJsonPath('review.payment_id', $id);
        $this->postJson($clearUrl, array_replace($clear, ['evidence_reference' => 'OTHER-LATE-CASH']))->assertConflict();
        $this->assertDatabaseCount('treasury_late_cash_receipts', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->getJson('/api/treasury/accounts/'.$account['id'])->assertOk()
            ->assertJsonPath('channel_unposted_late_cash_count', 0)
            ->assertJsonPath('provisional_recorded_balance_minor', 12525)->assertJsonPath('issues', []);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/invoices/'.$this->invoice->id.'/pay',
            $payload + ['deferred_sync' => true])->assertSuccessful()->assertJsonPath('payment.id', $id);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['id' => $id, 'cash_session_id' => null, 'user_id' => $this->admin->id]);
        $this->assertDatabaseMissing('payments', ['cash_session_id' => $new]);
        $this->assertDatabaseHas('cash_sessions', ['id' => $old, 'expected_amount' => 0, 'counted_amount' => 0, 'variance_amount' => 0]);
        $this->assertSame('125.25', $this->invoice->fresh()->amount_paid);
    }

    public function test_late_noncash_requires_actual_bank_clearance_without_rewriting_original_till(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $old = $this->postJson('/api/cash-sessions', [])->assertCreated()->json('cash_session.id');
        $this->postJson("/api/cash-sessions/{$old}/close", ['counted_amount' => 0])->assertOk();
        $reviewer = User::factory()->create(['role' => 'admin']);
        $reviewer->assignRole('super_admin');
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/cash-sessions/{$old}/approve", ['approve' => true])->assertOk();
        $this->actingAs($this->admin, 'sanctum');
        $new = $this->postJson('/api/cash-sessions', [])->assertCreated()->json('cash_session.id');
        $date = today()->toDateString();
        $payload = ['subscriber_id' => $this->subscriber->id, 'invoice_id' => $this->invoice->id,
            'cash_session_id' => $old, 'sync_uuid' => (string) Str::uuid(), 'amount' => '125.25',
            'payment_method' => 'check', 'payment_date' => $date, 'reference_number' => 'LATE-CHECK-001'];
        $id = $this->postJson('/api/late-receipt-reviews', $payload)->assertOk()->json('review.id');
        $bank = app(\App\Services\TreasuryRegisterService::class)->open($this->admin, [
            'code' => 'LATE-BANK', 'name' => 'Synthetic bank', 'channel' => 'bank',
            'opening_on' => $date, 'opening_amount' => '0', 'opening_reference' => 'LATE-BANK-OPEN',
            'opening_evidence' => 'Independent opening bank statement',
        ]);
        // Actual bank booking occurs after closure of the original till.
        $this->travel(1)->days();
        $clearDate = today()->toDateString();
        $statement = $this->postJson('/api/treasury/accounts/'.$bank['id'].'/bank-statements', [
            'reference' => 'LATE-BANK-STMT', 'from_date' => $date, 'to_date' => $clearDate,
            'opening_amount' => '0', 'closing_amount' => '130.25', 'evidence' => 'Actual bank statement with two credits',
            'lines' => [
                ['bank_reference' => 'LATE-IN', 'booked_on' => $clearDate, 'amount' => '125.25'],
                ['bank_reference' => 'OTHER-IN', 'booked_on' => $clearDate, 'amount' => '5.00'],
            ],
        ])->assertOk()->json('statement.id');
        $db = \Illuminate\Support\Facades\DB::class;
        $data = ['statement_id' => $statement, 'line_id' => $db::table('bank_statement_lines')->where('bank_reference', 'LATE-IN')->value('id'),
            'evidence_reference' => 'LATE-REVIEW-001', 'bank_clearance_reference' => 'LATE-PROOF-001',
            'evidence' => 'Independent verified bank clearance after till closure'];
        $url = "/api/late-receipt-reviews/{$id}/clear-bank";
        $this->postJson($url, $data)->assertForbidden();
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/late-receipt-reviews/{$id}/decide", [
            'approve' => true, 'settlement_method' => 'verified_non_cash', 'evidence_reference' => $data['evidence_reference'],
            'evidence' => $data['evidence'],
        ])->assertUnprocessable();
        $this->postJson($url, array_replace($data, ['line_id' => $db::table('bank_statement_lines')->where('bank_reference', 'OTHER-IN')->value('id')]))->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertSame('0.00', $this->invoice->fresh()->amount_paid);
        $paymentId = $this->postJson($url, $data)->assertOk()->assertJsonPath('review.status', 'approved')
            ->assertJsonPath('review.payment_date', $date)->assertJsonPath('bank.posting.amount_minor', 12525)
            ->assertJsonPath('bank.reconciliation.closing_difference_minor', 500)->json('review.payment_id');
        $this->postJson($url, $data)->assertOk()->assertJsonPath('review.payment_id', $paymentId);
        $this->postJson($url, array_replace($data, ['bank_clearance_reference' => 'OTHER-PROOF']))->assertConflict();
        $this->assertSame($clearDate, Payment::findOrFail($paymentId)->payment_date->toDateString());
        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'cash_session_id' => null, 'sync_uuid' => $payload['sync_uuid']]);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('bank_statement_matches', 1);
        $this->assertDatabaseHas('cash_sessions', ['id' => $old, 'expected_amount' => 0, 'counted_amount' => 0, 'variance_amount' => 0]);
        $this->assertDatabaseMissing('payments', ['cash_session_id' => $new]);
        $this->assertSame('125.25', $this->invoice->fresh()->amount_paid);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/invoices/'.$this->invoice->id.'/pay',
            $payload + ['deferred_sync' => true])->assertSuccessful()->assertJsonPath('payment.id', $paymentId);
        $this->assertDatabaseCount('payments', 1);
        $this->actingAs($reviewer, 'sanctum')->getJson('/api/treasury/accounts/'.$bank['id'])->assertOk()->assertJsonPath('issues', []);
        $db::table('late_receipt_reviews')->where('id', $id)->update(['bank_clearance_reference' => 'CORRUPTED-REF']);
        $this->getJson('/api/treasury/accounts/'.$bank['id'])->assertOk()->assertJsonPath('issues.0.code', 'bank_collection_source_mismatch');
        $this->travelBack();
    }

    public function test_counted_surplus_is_allocated_once_without_rewriting_closed_snapshot(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $old = $this->fundedSession($this->admin, '100.25')->id;
        $this->postJson("/api/cash-sessions/{$old}/close", ['counted_amount' => '350.75'])->assertOk()
            ->assertJsonPath('cash_session.variance_amount', '250.50');
        $supervisor = User::factory()->create(['role' => 'admin']);
        $supervisor->assignRole('super_admin');
        $this->actingAs($supervisor, 'sanctum')->postJson("/api/cash-sessions/{$old}/approve", ['approve' => true])->assertOk();
        $this->actingAs($this->admin, 'sanctum');
        $new = $this->postJson('/api/cash-sessions', [])->assertCreated()->json('cash_session.id');
        $payload = ['subscriber_id' => $this->subscriber->id, 'invoice_id' => $this->invoice->id,
            'cash_session_id' => $old, 'payment_method' => 'cash', 'payment_date' => today()->toDateString()];
        $ids = [];
        foreach (['150.25', '100.26', '100.25'] as $amount) {
            $ids[] = $this->postJson('/api/late-receipt-reviews', $payload + ['amount' => $amount, 'sync_uuid' => (string) Str::uuid()])
                ->assertSuccessful()->json('review.id');
        }
        $this->actingAs($supervisor, 'sanctum');
        $decision = ['approve' => true, 'evidence' => 'Verified receipt included in original cash count',
            'settlement_method' => 'already_counted'];
        $first = $decision + ['evidence_reference' => 'COUNTED-001'];
        $paymentId = $this->postJson("/api/late-receipt-reviews/{$ids[0]}/decide", $first)
            ->assertOk()->json('review.payment_id');
        $this->postJson("/api/late-receipt-reviews/{$ids[0]}/decide", $first)->assertOk()->assertJsonPath('review.payment_id', $paymentId);
        $this->postJson("/api/late-receipt-reviews/{$ids[1]}/decide", $decision + ['evidence_reference' => 'COUNTED-002'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson("/api/late-receipt-reviews/{$ids[2]}/decide", $decision + ['evidence_reference' => 'COUNTED-003'])->assertOk();
        $this->getJson("/api/cash-sessions/{$old}/report")->assertOk()
            ->assertJsonPath('cash_session.counted_cash_surplus_capacity', '250.50')
            ->assertJsonPath('cash_session.counted_cash_surplus_allocated', '250.50')
            ->assertJsonPath('cash_session.counted_cash_surplus_available', '0.00')
            ->assertJsonPath('cash_session.variance_amount', '250.50');
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseMissing('payments', ['cash_session_id' => $new]);
        $this->assertDatabaseMissing('payments', ['cash_session_id' => $old]);
        $this->assertDatabaseHas('cash_sessions', ['id' => $old, 'expected_amount' => '100.25', 'counted_amount' => '350.75', 'variance_amount' => '250.50']);
        $this->assertSame('250.50', $this->invoice->fresh()->amount_paid);
        $this->assertDatabaseCount('treasury_late_cash_receipts', 0);
        $this->assertDatabaseCount('treasury_postings', 1); // Opening funding only, not a second cash inflow.
        $this->postJson("/api/payments/{$paymentId}/void", ['reason' => 'Cannot void independently reviewed receipt'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment');
    }

    public function test_voiding_a_payment_restores_the_invoice_and_leaves_an_audit_trail(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/invoices/'.$this->invoice->id.'/pay', [
                'amount' => 600,
                'payment_method' => 'cash',
                'sync_uuid' => (string) Str::uuid(),
            ])
            ->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();
        $this->assertEquals('partially_paid', $this->invoice->fresh()->status);

        // السبب القصير مرفوض.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/payments/{$payment->id}/void", ['reason' => 'خطأ'])
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/payments/{$payment->id}/void", [
                'reason' => 'خطأ في إدخال المبلغ وتم تصحيحه مع المشترك',
            ])
            ->assertOk()
            ->assertJsonPath('payment.voided', true)
            ->assertJsonPath('receipt_data.voided', true);

        $payment->refresh();
        $this->assertNotNull($payment->voided_at);
        $this->assertSame($this->admin->id, $payment->voided_by);

        $this->invoice->refresh();
        $this->assertEquals(0, (float) $this->invoice->amount_paid);
        $this->assertSame('issued', $this->invoice->status);

        // السند لم يُحذف، لكنه لا يظهر في الاستعلام النشط.
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/payments')
            ->assertOk()
            ->assertJsonMissing(['id' => $payment->id]);

        $this->assertDatabaseHas('audit_events', [
            'event' => 'payment.voided',
            'entity_id' => (string) $payment->id,
        ]);
    }

    public function test_refresh_rotates_the_token_and_revokes_the_old_one(): void
    {
        $login = $this->postJson('/api/login', [
            'email' => $this->admin->email,
            'password' => 'password',
        ])->assertOk()->assertJsonStructure(['access_token', 'expires_at']);

        $oldToken = $login->json('access_token');

        $refreshed = $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->postJson('/api/refresh')
            ->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'user']);

        $newToken = $refreshed->json('access_token');
        $this->assertNotSame($oldToken, $newToken);

        // داخل اختبارات Laravel يتشارك كل الطلبات نسخة التطبيق نفسها، وحارس
        // RequestGuard يخزّن المستخدم بعد أول حل، لذا نُصفّي الحواجز المحفوظة
        // حتى يُعاد التحقق من التوكن فعلياً في الطلبات التالية (في الإنتاج كل
        // طلب يعمل بنسخة تطبيق مستقلة والسلوك صحيح بدون هذا السطر).
        $this->app->make(AuthManager::class)->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$newToken)
            ->getJson('/api/me')
            ->assertOk();

        $this->app->make(AuthManager::class)->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_a_revoked_device_cannot_call_the_api(): void
    {
        $device = Device::create([
            'user_id' => $this->admin->id,
            'device_uuid' => 'device-for-test',
            'name' => 'هاتف اختبار',
            'platform' => 'android',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Device-Id', 'device-for-test')
            ->getJson('/api/me')
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/devices/{$device->id}")
            ->assertOk();

        $this->assertNotNull($device->fresh()->revoked_at);

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Device-Id', 'device-for-test')
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_bulk_tariff_restore_and_force_delete(): void
    {
        $a = Tariff::create(['name' => 'أ', 'unit_price' => 100, 'is_active' => true, 'start_date' => '2000-01-01', 'end_date' => '2000-01-31']);
        $b = Tariff::create(['name' => 'ب', 'unit_price' => 200, 'is_active' => true, 'start_date' => '2000-02-01', 'end_date' => '2000-02-29']);
        $a->delete();
        $b->delete();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/tariffs/bulk-restore', ['ids' => [$a->id]])
            ->assertOk()
            ->assertJsonPath('restored_count', 1);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/tariffs/bulk-force-delete', ['ids' => [$b->id]])
            ->assertOk()
            ->assertJsonPath('force_deleted_count', 1);

        $this->assertDatabaseMissing('tariffs', ['id' => $b->id]);
        $this->assertDatabaseHas('tariffs', ['id' => $a->id, 'deleted_at' => null]);
    }

    public function test_daily_cash_session_pdf_report_is_rendered_from_server_data(): void
    {
        Pdf::fake();

        $sessionId = $this->openSessionWithOnePayment();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/cash-sessions/{$sessionId}/close", [
                'counted_amount' => 1000,
                'notes' => 'العدّ مطابق للسندات.',
            ])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->get("/api/cash-sessions/{$sessionId}/report/pdf")
            ->assertOk();

        Pdf::assertRespondedWithPdf(function (PdfBuilder $pdf) {
            return $pdf->isDownload()
                && $pdf->viewName === 'pdf.cash_session'
                && str_ends_with($pdf->downloadName, '.pdf')
                && $pdf->contains(['تقرير صندوق المحصّل', 'نقداً', 'الفرق']);
        });
    }

    public function test_pdf_report_contains_receipts_and_counted_amount_in_words(): void
    {
        Pdf::fake();

        $sessionId = $this->openSessionWithOnePayment();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/cash-sessions/{$sessionId}/close", ['counted_amount' => 1000])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->get("/api/cash-sessions/{$sessionId}/report/pdf")
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn (PdfBuilder $pdf) => $pdf->contains([
                'سندات الصندوق',
                'النقد المعدود كتابةً',
                '1000.00',
            ]),
        );
    }

    public function test_a_collector_cannot_read_another_collectors_pdf_report(): void
    {
        Pdf::fake();

        $sessionId = $this->openSessionWithOnePayment();

        $other = User::factory()->create(['role' => 'collector']);
        $other->assignRole('collector');

        $this->actingAs($other, 'sanctum')
            ->get("/api/cash-sessions/{$sessionId}/report/pdf")
            ->assertForbidden();
    }

    /** يفتح صندوقاً للمدير ويسجّل سنداً واحداً عليه، ويعيد رقم الصندوق. */
    private function openSessionWithOnePayment(): int
    {
        $open = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/cash-sessions', ['opening_amount' => 0])
            ->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/invoices/{$this->invoice->id}/pay", [
                'amount' => 1000,
                'payment_method' => 'cash',
                'sync_uuid' => (string) Str::uuid(),
            ])
            ->assertCreated();

        return (int) $open->json('cash_session.id');
    }
}

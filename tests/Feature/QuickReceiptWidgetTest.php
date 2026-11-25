<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\CustomAccountWidget;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\User;
use App\Services\CashSessionService;
use App\Services\InvoiceService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class QuickReceiptWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role = 'collector', bool $allowed = true): User
    {
        $user = User::factory()->create(['role' => $role]);
        if ($allowed) {
            $user->givePermissionTo(Permission::findOrCreate('Create:Payment', 'web'));
        }
        $this->actingAs($user, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    private function account(string $name): Subscriber
    {
        return Subscriber::create([
            'name' => $name, 'square_id' => Square::create(['name' => $name])->id,
            'status' => 'active',
        ]);
    }

    private function bill(Subscriber $subscriber)
    {
        Tariff::create(['name' => 'Synthetic widget tariff', 'unit_price' => 600, 'is_active' => true]);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'WIDGET-METER']);
        $this->createMeasuredReading([
            'meter_id' => $meter->id, 'reading_date' => '2026-01-31',
            'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
            'previous_reading' => 0, 'current_reading' => 1,
        ]);

        return app(InvoiceService::class)->generateInvoice($subscriber, $meter);
    }

    private function data(Subscriber $subscriber, array $extra = []): array
    {
        return array_replace([
            'subscriber_id' => (string) $subscriber->id, 'invoice_id' => null,
            'amount' => '100.25', 'payment_date' => today()->toDateString(),
            'payment_method' => 'cash', 'reason' => 'Synthetic receipt',
        ], $extra);
    }

    public function test_mounted_action_does_not_generate_number_and_submission_posts_through_real_service(): void
    {
        $actor = $this->actor();
        $subscriber = $this->account('Widget receipt');
        $invoice = $this->bill($subscriber);
        $session = app(CashSessionService::class)->open($actor);
        $widget = Livewire::test(CustomAccountWidget::class)->assertActionVisible('createPayment');
        DB::enableQueryLog();
        try {
            $widget->mountAction('createPayment')->assertSet('mountedActions.0.data.receipt_number', null);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        foreach ($queries as $query) {
            self::assertStringNotContainsString('receipt_number', strtolower($query['query']));
        }
        $this->assertDatabaseCount('payments', 0);
        $widget->unmountAction()->mountAction('createPayment')
            ->fillForm($this->data($subscriber, ['invoice_id' => (string) $invoice->id]))
            ->set('mountedActions.0.data.receipt_number', 'FORGED-RECEIPT')
            ->callMountedAction()->assertHasNoActionErrors();

        $payment = Payment::sole();
        self::assertMatchesRegularExpression('/^REC-\d{6}-\d{4,}$/', $payment->receipt_number);
        self::assertNotSame('FORGED-RECEIPT', $payment->receipt_number);
        self::assertSame($actor->id, (int) $payment->user_id);
        self::assertSame($session->id, (int) $payment->cash_session_id);
        self::assertSame($invoice->id, (int) $payment->invoice_id);
        self::assertSame('100.25', $payment->amount);
        self::assertSame('100.25', $invoice->fresh()->amount_paid);
        $this->assertDatabaseHas('transactions', [
            'subscriber_id' => $subscriber->id, 'reference_type' => Payment::class,
            'reference_id' => $payment->id, 'type' => 'payment', 'amount' => 100.25,
        ]);
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount_minor' => 10025,
        ]);
        self::assertSame(499.75, $subscriber->getAccountDebt());
        self::assertSame(100.25, app(CashSessionService::class)->expectedAmount($session));
        $this->assertDatabaseCount('noncash_collection_claims', 0);
    }

    public function test_non_cash_methods_cannot_be_submitted_as_receipts_even_by_authorized_admin(): void
    {
        $this->actor('admin');
        $subscriber = $this->account('Uncleared methods');
        foreach (['check', 'bank_transfer'] as $method) {
            Livewire::test(CustomAccountWidget::class)
                ->callAction('createPayment', data: $this->data($subscriber, ['payment_method' => $method]))
                ->assertHasActionErrors(['payment_method']);
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('noncash_collection_claims', 0);
    }

    public function test_missing_permission_or_disallowed_role_cannot_mount_and_submit_action(): void
    {
        $subscriber = $this->account('Authorization');
        foreach ([['collector', false], ['meter_reader', true]] as [$role, $allowed]) {
            $this->actor($role, $allowed);
            Livewire::test(CustomAccountWidget::class)
                ->assertActionHidden('createPayment')
                ->mountAction('createPayment')
                ->assertSet('mountedActions', [])
                ->call('callMountedAction');
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_cross_subscriber_invoice_and_invalid_amount_are_rejected(): void
    {
        $this->actor('admin');
        $subscriber = $this->account('Wrong account');
        $other = $this->account('Invoice owner');
        $invoice = $this->bill($other);

        Livewire::test(CustomAccountWidget::class)
            ->callAction('createPayment', data: $this->data($subscriber, ['invoice_id' => (string) $invoice->id]))
            ->assertHasActionErrors(['invoice_id']);
        foreach (['0', '-1', '1.234', '10000000000', '1e2'] as $amount) {
            Livewire::test(CustomAccountWidget::class)
                ->callAction('createPayment', data: $this->data($subscriber, ['amount' => $amount]))
                ->assertHasActionErrors(['amount']);
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 1); // Only the original invoice.
        self::assertSame('0.00', $invoice->fresh()->amount_paid);
    }

    public function test_permission_revoked_after_mount_cannot_issue_a_receipt(): void
    {
        $actor = $this->actor('admin');
        $subscriber = $this->account('Revoked permission');
        $widget = Livewire::test(CustomAccountWidget::class)->mountAction('createPayment')
            ->fillForm($this->data($subscriber));
        $actor->revokePermissionTo('Create:Payment');
        // Simulate a new request's authenticated user after permission revocation.
        $this->actingAs($actor->fresh(), 'web');
        $widget->call('callMountedAction');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_allocation_failure_rolls_back_widget_receipt(): void
    {
        $actor = $this->actor('collector');
        $subscriber = $this->account('Rollback widget receipt');
        $invoice = $this->bill($subscriber);
        $session = app(CashSessionService::class)->open($actor);
        $before = DB::table('payment_allocation_revisions')->count();
        $this->mock(\App\Services\PaymentAllocationHistory::class, function ($mock) {
            $mock->shouldReceive('append')->once()->andThrow(new \RuntimeException('Synthetic widget allocation failure'));
        });
        try {
            Livewire::test(CustomAccountWidget::class)
                ->callAction('createPayment', data: $this->data($subscriber, ['invoice_id' => (string) $invoice->id]));
            self::fail('A failed allocation must not produce a receipt.');
        } catch (\RuntimeException $e) {
            self::assertSame('Synthetic widget allocation failure', $e->getMessage());
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('payment_allocation_revisions', $before);
        self::assertSame('0.00', $invoice->fresh()->amount_paid);
        self::assertSame(0.0, app(CashSessionService::class)->expectedAmount($session));
    }

    public function test_two_saved_cash_receipts_get_distinct_numbers_without_an_invoice(): void
    {
        $actor = $this->actor('admin');
        $subscriber = $this->account('Unallocated cash');
        $widget = Livewire::test(CustomAccountWidget::class);
        for ($i = 0; $i < 2; $i++) {
            $widget->callAction('createPayment', data: $this->data($subscriber))
                ->assertHasNoActionErrors();
        }
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('transactions', 2);
        self::assertSame(2, Payment::query()->distinct()->count('receipt_number'));
        self::assertSame(2, Payment::where('user_id', $actor->id)->whereNull('invoice_id')->count());
        self::assertSame(200.50, $subscriber->getAccountBalance());
    }
}

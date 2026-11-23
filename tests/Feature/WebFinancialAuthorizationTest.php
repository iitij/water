<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WebFinancialAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function protectedUrls(): array
    {
        $square = Square::create(['name' => 'Authorization test']);
        $subscriber = Subscriber::create([
            'name' => 'Authorization subscriber',
            'status' => 'active',
            'square_id' => $square->id,
        ]);
        $invoice = Invoice::create([
            'subscriber_id' => $subscriber->id,
            'bill_number' => 'INV-AUTH-1',
            'bill_date' => '2026-11-03',
            'total_amount' => 100,
            'status' => 'issued',
        ]);
        $payment = Payment::create([
            'subscriber_id' => $subscriber->id,
            'invoice_id' => $invoice->id,
            'receipt_number' => 'REC-AUTH-1',
            'amount' => 20,
            'payment_date' => '2026-11-03',
            'payment_method' => 'cash',
        ]);

        return [
            route('invoices.print', $invoice),
            route('payments.print', $payment),
            route('admin.export.subscribers'),
            route('admin.export.invoices'),
            route('admin.export.readings'),
        ];
    }

    public function test_guest_cannot_print_or_export_financial_data(): void
    {
        foreach ($this->protectedUrls() as $url) {
            $this->get($url)->assertRedirect(route('filament.admin.auth.login'));
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public function test_authenticated_user_without_required_permissions_is_denied(): void
    {
        $urls = $this->protectedUrls();
        $user = User::factory()->create(['role' => 'reader']);
        $this->actingAs($user, 'web');

        foreach ($urls as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_permissions_for_one_resource_do_not_grant_other_exports(): void
    {
        $urls = $this->protectedUrls();
        $user = User::factory()->create(['role' => 'reader']);
        $user->givePermissionTo(Permission::findOrCreate('ViewAny:Subscriber', 'web'));
        $this->actingAs($user, 'web');

        foreach ([$urls[0], $urls[1], $urls[3], $urls[4]] as $url) {
            $this->get($url)->assertForbidden();
        }
    }
}

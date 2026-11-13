<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_digit_path_search_supports_western_arabic_and_persian_digits(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        // A different route containing the digit must not be returned.
        $this->meter('AA', 16);
        $meter = $this->meter('ZZ', 6);
        foreach (['6', '٦', '۶', ' 6 '] as $term) {
            $response = $this->getJson('/api/search?'.http_build_query(['q' => $term, 'limit' => 1]))
                ->assertOk()->assertJsonPath('min_length', 1);
            $this->assertSame(['subscriber', 'meter'], array_column($response->json('groups'), 'type'));
            $group = collect($response->json('groups'))->firstWhere('type', 'meter');
            $this->assertNotNull($group);
            $this->assertCount(1, $group['items']);
            $this->assertSame($meter->id, $group['items'][0]['id']);
        }
    }

    public function test_single_letters_and_blank_queries_still_do_not_search(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $this->meter('AB', 6);
        foreach (['A', 'م', '', ' '] as $term) {
            $this->getJson('/api/search?'.http_build_query(['q' => $term]))
                ->assertOk()->assertJsonPath('min_length', 2)->assertJsonPath('groups', []);
        }
    }

    public function test_digits_search_only_the_exact_route_not_names_phone_codes_or_square_names(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $square = Square::create(['name' => 'مربع 1']);
        $matching = Subscriber::create(['name' => 'المطابق', 'square_id' => $square->id, 'status' => 'active']);
        $misleading = Subscriber::create(['name' => 'مشترك 1', 'phone' => '711111111', 'square_id' => $square->id, 'status' => 'active']);
        $meter = $matching->meters()->create(['unique_code' => 'MATCH', 'path_number' => 1, 'status' => 'active']);
        $misleading->meters()->create(['unique_code' => 'METER-1', 'path_number' => 11,
            'status' => 'active']);

        foreach (['1', '١', '۱', ' 1 '] as $term) {
            $response = $this->getJson('/api/search?'.http_build_query(['q' => $term, 'limit' => 10]))->assertOk();
            $groups = collect($response->json('groups'))->keyBy('type');
            $this->assertSame([$meter->id], array_column($groups['meter']['items'], 'id'));
            $this->assertSame([$matching->id], array_column($groups['subscriber']['items'], 'id'));
            $this->assertFalse($groups->has('square'));
        }
        $this->getJson('/api/search?q=111')->assertOk()->assertJsonPath('groups', []);
        $this->getJson('/api/search?q=METER-1')->assertOk()->assertJsonPath('groups.0.items.0.title', 'عداد METER-1');
    }

    public function test_route_finance_search_isolates_linked_meters_but_keeps_general_subscriber_records(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user, 'sanctum');
        $meter = $this->meter('MATCH', 1);
        $subscriber = $meter->subscriber;
        $otherMeter = $this->meter('OTHER-1', 11);
        // Historic second meter can still own invoices and payments, but is
        // not a second CURRENT meter for this subscriber.
        $otherMeter->update([
            'subscriber_id' => $subscriber->id,
            'removed_at' => '2026-10-01',
            'status' => 'inactive',
        ]);
        $invoiceIds = [];
        $paymentIds = [];
        foreach ([$meter->id, null, $otherMeter->id] as $index => $meterId) {
            $invoice = Invoice::withoutEvents(fn () => Invoice::create([
                'subscriber_id' => $subscriber->id, 'meter_id' => $meterId, 'invoice_type' => 'manual',
                'bill_number' => 'BILL-1-'.$index, 'bill_date' => '2026-11-03', 'total_amount' => 50, 'status' => 'issued',
            ]));
            $payment = Payment::withoutEvents(fn () => Payment::create([
                'subscriber_id' => $subscriber->id, 'meter_id' => $meterId, 'user_id' => $user->id,
                'receipt_number' => 'RECEIPT-1-'.$index, 'reference_number' => 'REF-1',
                'payment_date' => '2026-11-03', 'payment_method' => 'cash', 'amount' => 20,
            ]));
            if ($index < 2) {
                $invoiceIds[] = $invoice->id;
                $paymentIds[] = $payment->id;
            }
        }
        $groups = collect($this->getJson('/api/search?q=1&limit=10')->assertOk()->json('groups'))->keyBy('type');
        $this->assertEqualsCanonicalizing($invoiceIds, array_column($groups['invoice']['items'], 'id'));
        $this->assertEqualsCanonicalizing($paymentIds, array_column($groups['payment']['items'], 'id'));
    }

    public function test_numeric_search_preserves_group_permissions_and_authentication(): void
    {
        $this->meter('ZZ', 6);
        $this->getJson('/api/search?q=6')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'unprivileged']), 'sanctum');
        $this->getJson('/api/search?q=6')->assertOk()->assertJsonPath('groups', []);
        $this->actingAs(User::factory()->create(['role' => 'reader']), 'sanctum');
        $response = $this->getJson('/api/search?q=6')->assertOk();
        $this->assertNotNull(collect($response->json('groups'))->firstWhere('type', 'meter'));
    }

    public function test_two_character_text_search_and_maximum_group_limit_are_preserved(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        for ($index = 0; $index < 12; $index++) {
            $this->meter('AB-'.$index, 6);
        }
        $this->getJson('/api/search?q=AB&limit=100')
            ->assertOk()->assertJsonPath('min_length', 2)->assertJsonCount(10, 'groups.0.items');
        $response = $this->getJson('/api/search?q=6&limit=100')
            ->assertOk()->assertJsonPath('min_length', 1);
        $group = collect($response->json('groups'))->firstWhere('type', 'meter');
        $this->assertNotNull($group);
        $this->assertCount(10, $group['items']);
    }

    private function meter(string $code, int $path): Meter
    {
        $square = Square::firstOrCreate(['name' => 'حي الاختبار']);
        // Every operational meter belongs to a distinct subscriber. Do not
        // revive the obsolete multi-current-meter test fixture.
        $subscriber = Subscriber::create([
            'name' => 'مشترك الاختبار', 'square_id' => $square->id, 'status' => 'active',
        ]);

        return $subscriber->meters()->create([
            'unique_code' => $code, 'path_number' => $path, 'status' => 'active',
        ]);
    }
}

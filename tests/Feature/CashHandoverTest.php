<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CashSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CashHandoverTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_approved_count_is_not_a_handover_and_partial_receipts_are_bounded_and_idempotent(): void
    {
        $role = Role::findOrCreate('super_admin', 'web');
        $collector = User::factory()->create(['role' => 'admin']);
        $collector->assignRole($role);
        $receiver = User::factory()->create(['role' => 'admin']);
        $receiver->assignRole($role);
        $sessions = app(CashSessionService::class);
        $session = $this->fundedSession($collector, '100.25');
        $session = $sessions->close($session, 150.75);
        $endpoint = '/api/cash-sessions/'.$session->id.'/handovers';
        $payload = ['amount' => '100.25', 'reference' => 'HANDOVER-001',
            'received_on' => today()->toDateString(), 'evidence' => 'Actual counted cash received independently'];
        $this->actingAs($receiver, 'sanctum')->postJson($endpoint, $payload)->assertUnprocessable();
        $session = $sessions->decide($session, true, $receiver);
        $this->actingAs($collector, 'sanctum')->postJson($endpoint, $payload)->assertForbidden();
        $this->actingAs($receiver, 'sanctum')->getJson('/api/cash-sessions/'.$session->id.'/report')
            ->assertOk()->assertJsonPath('cash_session.treasury_handover.status', 'pending')
            ->assertJsonPath('cash_session.treasury_handover.remaining_amount', '150.75');
        $id = $this->postJson($endpoint, $payload)->assertOk()
            ->assertJsonPath('treasury_handover.status', 'partial')
            ->assertJsonPath('treasury_handover.remaining_amount', '50.50')->json('handover.id');
        $this->postJson($endpoint, $payload)->assertOk()->assertJsonPath('handover.id', $id);
        $this->postJson($endpoint, array_replace($payload, ['amount' => '99.25']))->assertStatus(409);
        $this->postJson($endpoint, array_replace($payload, ['reference' => 'HANDOVER-002', 'amount' => '50.51']))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson($endpoint, array_replace($payload, ['reference' => 'HANDOVER-003', 'amount' => '50.50']))
            ->assertOk()->assertJsonPath('treasury_handover.status', 'complete')
            ->assertJsonPath('treasury_handover.remaining_amount', '0.00');
        $this->getJson('/api/cash-sessions/'.$session->id.'/report')->assertOk()
            ->assertJsonPath('cash_session.treasury_handover.received_amount', '150.75')
            ->assertJsonPath('handovers.0.reference', 'HANDOVER-001')
            ->assertJsonPath('handovers.0.amount', '100.25')
            ->assertJsonPath('handovers.1.reference', 'HANDOVER-003')
            ->assertJsonPath('handovers.1.amount', '50.50');
        $this->assertDatabaseCount('cash_handovers', 2);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('cash_sessions', ['id' => $session->id, 'expected_amount' => '100.25',
            'counted_amount' => '150.75', 'variance_amount' => '50.50', 'state' => 'approved']);
    }
}

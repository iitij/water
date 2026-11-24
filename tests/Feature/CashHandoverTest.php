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

    public function test_approved_count_is_not_a_handover_and_partial_receipts_are_bounded_and_idempotent(): void
    {
        $role = Role::findOrCreate('super_admin', 'web');
        $collector = User::factory()->create(['role' => 'admin']);
        $collector->assignRole($role);
        $receiver = User::factory()->create(['role' => 'admin']);
        $receiver->assignRole($role);
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($collector, 100.25);
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
        $this->assertDatabaseCount('cash_handovers', 2);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('cash_sessions', ['id' => $session->id, 'expected_amount' => '100.25',
            'counted_amount' => '150.75', 'variance_amount' => '50.50', 'state' => 'approved']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\MobileAppLog;
use App\Models\MobileDeveloperSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileDeveloperModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pin_verification_requires_authentication_and_valid_pin(): void
    {
        $this->postJson('/api/developer/verify-pin', ['pin' => '77288'])->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/developer/verify-pin', ['pin' => '00000'])
            ->assertUnprocessable();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/developer/verify-pin', ['pin' => '77288'])
            ->assertOk()
            ->assertJsonPath('authorized', true);

        $this->assertSame(64, strlen($response->json('grant')));
        $this->assertTrue(Hash::check('77288', MobileDeveloperSetting::current()->pin_hash));
    }

    public function test_grant_is_bound_to_user_and_device_and_pin_rotation_revokes_it(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $grant = $this->actingAs($owner, 'sanctum')
            ->withHeader('X-Device-Id', 'device-A')
            ->postJson('/api/developer/verify-pin', ['pin' => '77288'])
            ->assertOk()
            ->json('grant');
        $this->actingAs($owner, 'sanctum')
            ->withHeaders(['X-Device-Id' => 'device-A', 'X-Developer-Grant' => $grant])
            ->getJson('/api/developer/config')
            ->assertOk()
            ->assertJsonPath('authorized', true);

        $this->actingAs($other, 'sanctum')
            ->withHeaders(['X-Device-Id' => 'device-A', 'X-Developer-Grant' => $grant])
            ->getJson('/api/developer/config')
            ->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->withHeaders(['X-Device-Id' => 'device-B', 'X-Developer-Grant' => $grant])
            ->getJson('/api/developer/config')
            ->assertForbidden();

        $settings = MobileDeveloperSetting::current();
        $settings->pin_hash = Hash::make('99112');
        $settings->save();
        $this->actingAs($owner, 'sanctum')
            ->withHeaders(['X-Device-Id' => 'device-A', 'X-Developer-Grant' => $grant])
            ->getJson('/api/developer/config')
            ->assertForbidden();
        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/developer/verify-pin', ['pin' => '77288'])
            ->assertUnprocessable();
        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/developer/verify-pin', ['pin' => '99112'])
            ->assertOk();
    }

    public function test_mobile_log_upload_needs_grant_and_redacts_secrets(): void
    {
        $user = User::factory()->create();
        $payload = ['logs' => [[
            'level' => 'error',
            'source' => 'api',
            'message' => 'Bearer hidden-token failed https://host/api?token=secret-value',
        ]]];

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/developer/mobile-logs', $payload)
            ->assertForbidden();

        $grant = $this->actingAs($user, 'sanctum')
            ->postJson('/api/developer/verify-pin', ['pin' => '77288'])
            ->assertOk()
            ->json('grant');
        $this->withHeader('X-Developer-Grant', $grant)
            ->postJson('/api/developer/mobile-logs', $payload)
            ->assertOk()
            ->assertJsonPath('received_count', 1);

        $message = MobileAppLog::query()->sole()->message;
        $this->assertStringNotContainsString('hidden-token', $message);
        $this->assertStringNotContainsString('secret-value', $message);
        $this->assertStringContainsString('[REDACTED]', $message);
    }

    public function test_mobile_log_upload_is_bounded_to_fifty_entries(): void
    {
        $user = User::factory()->create();
        $grant = $this->actingAs($user, 'sanctum')
            ->postJson('/api/developer/verify-pin', ['pin' => '77288'])
            ->assertOk()
            ->json('grant');
        $this->withHeader('X-Developer-Grant', $grant)
            ->postJson('/api/developer/mobile-logs', [
                'logs' => array_fill(0, 51, ['level' => 'info', 'message' => 'test']),
            ])
            ->assertUnprocessable();
        $this->assertSame(0, MobileAppLog::count());
    }
}

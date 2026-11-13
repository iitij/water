<?php

namespace Tests\Feature;

use App\Filament\Resources\ReadingResource\Pages\CreateReading;
use App\Models\Meter;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SingleMeterFilamentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_filament_livewire_form_rejects_a_new_reading_on_a_retired_meter(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        foreach (['ViewAny:Reading', 'Create:Reading', 'ViewAny:Meter', 'View:Meter'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $square = Square::create(['name' => 'Filament test area']);
        $subscriber = Subscriber::create([
            'name' => 'Historical meter subscriber',
            'square_id' => $square->id,
            'status' => 'active',
        ]);
        $meter = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'FILAMENT-HISTORY-ONLY',
            'removed_at' => '2026-10-01',
        ]);

        Livewire::test(CreateReading::class)
            ->fillForm([
                'meter_id' => $meter->id,
                'reading_date' => '2026-11-12',
                'previous_reading' => 0,
                'current_reading' => 30,
                'period_start' => '2026-11-01',
                'period_end' => '2026-11-30',
            ])
            ->call('create')
            ->assertHasFormErrors(['meter_id']);

        $this->assertDatabaseCount('readings', 0);
    }
}

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

    public function test_filament_form_preflight_cannot_save_after_meter_replacement(): void
    {
        $square = Square::create(['name' => 'Form replacement area']);
        $subscriber = Subscriber::create([
            'name' => 'Form replacement subscriber',
            'square_id' => $square->id,
            'status' => 'active',
        ]);
        $old = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'STALE-FORM-OLD', 'installed_at' => now()->subDays(10)->toDateString()]);
        $page = (new \ReflectionClass(CreateReading::class))->newInstanceWithoutConstructor();
        $preflight = new \ReflectionMethod(CreateReading::class, 'mutateFormDataBeforeCreate');
        $save = new \ReflectionMethod(CreateReading::class, 'handleRecordCreation');
        $prepared = $preflight->invoke($page, [
            'meter_id' => $old->id,
            'reading_date' => now()->toDateString(),
            'current_reading' => 20,
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
        ]);

        app(\App\Services\SingleMeterService::class)->replace(
            $old,
            ['unique_code' => 'STALE-FORM-NEW'],
            now()->toDateString(),
            'استبدال أثناء تحرير نموذج القراءة',
        );
        try {
            $save->invoke($page, $prepared);
            $this->fail('A pre-validated stale form must not insert a reading for a deleted meter.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $this->assertDatabaseCount('readings', 0);
            $this->assertDatabaseCount('meters', 1);
        }
    }

    public function test_filament_persisted_reading_uses_locked_current_previous_value(): void
    {
        $square = Square::create(['name' => 'Locked reading area']);
        $subscriber = Subscriber::create([
            'name' => 'Locked reading subscriber',
            'square_id' => $square->id,
            'status' => 'active',
        ]);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'LOCKED-READING', 'installed_at' => now()->subDays(10)->toDateString()]);
        $page = (new \ReflectionClass(CreateReading::class))->newInstanceWithoutConstructor();
        $save = new \ReflectionMethod(CreateReading::class, 'handleRecordCreation');

        $created = $save->invoke($page, [
            'meter_id' => $meter->id,
            'reading_date' => now()->toDateString(),
            'previous_reading' => 999,
            'current_reading' => 12,
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
        ]);
        $this->assertSame($meter->id, $created->meter_id);
        $this->assertSame('0.00', (string) $created->previous_reading);
        $this->assertDatabaseCount('readings', 1);
    }
}

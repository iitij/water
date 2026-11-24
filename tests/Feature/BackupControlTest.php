<?php

namespace Tests\Feature;

use App\Filament\Pages\BackupSettings;
use App\Models\User;
use App\Support\BackupControl;
use DateTimeImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BackupControlTest extends TestCase
{
    use RefreshDatabase;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/water-backup-test-'.bin2hex(random_bytes(10));
        config(['water-backup.policy_dir' => $this->dir.'/policy', 'water-backup.state_dir' => $this->dir.'/state',
            'water-backup.archive_dir' => $this->dir.'/archives']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_super_admin_can_change_schedule_and_device_using_native_page(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $this->get(BackupSettings::getUrl())->assertOk()->assertSee('إعدادات النسخ الاحتياطي')->assertSee('fi-section', false);
        Livewire::test(BackupSettings::class)->fillForm([
            'enabled' => true, 'interval_hours' => 12, 'time' => '08:15', 'destination' => 'server',
        ])->call('submit')->assertHasNoErrors();
        $p = app(BackupControl::class)->policy();
        $this->assertSame(12, $p['interval_hours']);
        $this->assertSame('08:15', $p['time']);
        $this->assertFalse(app(BackupControl::class)->deviceAllowed());
        $this->assertSame(0600, fileperms($this->dir.'/policy/policy.json') & 0777);
    }

    public function test_ordinary_user_cannot_access_backup_settings(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(BackupSettings::getUrl())->assertForbidden();
    }

    public function test_yemen_schedule_deduplicates_and_retries_failures(): void
    {
        $c = app(BackupControl::class);
        $now = new DateTimeImmutable('2026-11-24T04:00:00+03:00');
        $c->save(['enabled' => true, 'destination' => 'msi', 'interval_hours' => 6, 'time' => '06:00'], $now);
        $this->assertSame((new DateTimeImmutable('2026-11-24T03:00:00Z'))->getTimestamp(), $c->nextDue($now));
        $due = new DateTimeImmutable('2026-11-24T06:00:00+03:00');
        $calls = 0;
        $run = function () use (&$calls) { $calls++; return $calls > 1; };
        $this->assertSame('not-due', $c->tick($run, $now));
        $this->assertSame('failed', $c->tick($run, $due));
        $this->assertSame('retry-wait', $c->tick($run, $due->modify('+5 minutes')));
        $this->assertSame('success', $c->tick($run, $due->modify('+30 minutes')));
        $this->assertSame('already-complete', $c->tick($run, $due->modify('+35 minutes')));
        $this->assertSame(2, $calls);
        $this->assertSame($due->modify('+6 hours')->getTimestamp(), $c->nextDue($due->modify('+35 minutes')));
        $old = $c->policy();
        $c->save(['enabled' => true, 'destination' => 'server', 'interval_hours' => 6, 'time' => '06:00'], $due);
        $this->assertSame($old['anchor'], $c->policy()['anchor']);
        $c->save(['enabled' => false, 'destination' => 'server', 'interval_hours' => 6, 'time' => '06:00'], $due);
        $this->assertNull($c->nextDue($due));
        $this->assertSame('not-due', $c->tick($run, $due->modify('+1 day')));
    }

    public function test_device_receipt_is_bound_to_known_archive_checksum(): void
    {
        $c = app(BackupControl::class);
        $name = 'water-20261124T021342Z-412d1e';
        $digest = str_repeat('a', 64);
        File::ensureDirectoryExists($this->dir.'/archives/'.$name);
        File::put($this->dir.'/archives/'.$name.'/'.$name.'.tar.gz.gpg.sha256', $digest.'  '.$name.".tar.gz.gpg\n");
        $c->acknowledge($name, $digest);
        $this->assertSame($name, $c->deviceStatus()['archive']);
        $this->expectException(\RuntimeException::class);
        $c->acknowledge($name, str_repeat('b', 64));
    }

    public function test_invalid_interval_or_unregistered_device_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        app(BackupControl::class)->save(['enabled' => true, 'destination' => '../../tmp', 'interval_hours' => 1, 'time' => '00:01']);
    }
}

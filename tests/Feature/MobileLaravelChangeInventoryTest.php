<?php

namespace Tests\Feature;

use App\Filament\Pages\MobileDeveloperSettings;
use App\Models\User;
use App\Support\MobileLaravelChangeInventory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MobileLaravelChangeInventoryTest extends TestCase
{
    use RefreshDatabase;

    private const SCOPE = [
        'app', 'routes', 'database', 'config', 'bootstrap', 'resources', 'tests',
        'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
        'docker', 'docker-compose.yml', 'phpunit.xml', '.env.docker.example',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_snapshot_has_safe_unique_paths_and_accurate_totals_without_reclassifying_original_code(): void
    {
        $snapshot = MobileLaravelChangeInventory::snapshot();
        $this->assertSame('783afe1c01c64ea95b4dabb5e4f488e58820f023', $snapshot['baseline']);
        $this->assertSame('4f058443f75eff18e0d3cb56bcecbfe180f487d1', $snapshot['revision']);
        $this->assertSame('2026-10-29', $snapshot['audited_at']);
        $files = [];
        foreach ($snapshot['groups'] as $group) {
            foreach (['id', 'title', 'summary', 'changes', 'files'] as $key) {
                $this->assertArrayHasKey($key, $group);
            }
            $this->assertNotEmpty($group['changes']);
            foreach ($group['files'] as $file) {
                $this->assertContains($file['scope'], ['production', 'database', 'test', 'environment']);
                $this->assertSafePath($file['path']);
                $this->assertContains($file['status'], ['A', 'M']);
                $this->assertFileExists(base_path($file['path']));
                $files[] = $file;
            }
        }
        $paths = array_column($files, 'path');
        $this->assertCount(count($paths), array_unique($paths));
        $this->assertSame([
            'files' => count($files),
            'added' => count(array_filter($files, fn (array $file): bool => $file['status'] === 'A')),
            'modified' => count(array_filter($files, fn (array $file): bool => $file['status'] === 'M')),
            'migrations' => count(array_filter($files, fn (array $file): bool => str_starts_with($file['path'], 'database/migrations/'))),
            'groups' => count($snapshot['groups']),
        ], $snapshot['totals']);
        $this->assertSame(89, $snapshot['totals']['files']);
        $this->assertSame(21, $snapshot['totals']['groups']);
        $this->assertSame(12, $snapshot['totals']['migrations']);
        $statusByPath = array_column($files, 'status', 'path');
        $this->assertSame('M', $statusByPath['app/Http/Controllers/Api/AuthController.php']);
        $this->assertSame('M', $statusByPath['app/Models/Payment.php']);
        $this->assertArrayNotHasKey('app/Services/InvoiceService.php', $statusByPath);
        $this->assertArrayNotHasKey('database/migrations/2024_01_01_000006_create_invoices_table.php', $statusByPath);

        foreach ($snapshot['ancillary_changes'] as $group) {
            foreach ($group['files'] as $file) {
                $this->assertSafePath($file['path']);
                // Ancillary records expose names/status, never debug-file contents.
                $this->assertSame(['path', 'status'], array_keys($file));
            }
        }
        foreach ($snapshot['local_changes'] as $change) {
            $this->assertSafePath($change['path']);
            $this->assertNotEmpty($change['description']);
        }
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('77288', $json);
        $this->assertDoesNotMatchRegularExpression('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|base64:[A-Za-z0-9+\/]{20,}/', $json);
    }

    public function test_inventory_matches_reviewed_git_diff_and_covers_every_changed_path_outside_flutter(): void
    {
        $snapshot = MobileLaravelChangeInventory::snapshot();
        foreach ([$snapshot['baseline'], $snapshot['revision']] as $revision) {
            try {
                $check = new Process(['git', 'cat-file', '-e', $revision.'^{commit}'], base_path());
                $check->run();
            } catch (\Throwable) {
                $this->markTestSkipped('Git is unavailable; manifest schema and authorization remain covered separately.');
            }
            if (! $check->isSuccessful()) {
                $this->markTestSkipped('Reviewed commits are unavailable in this checkout or its shallow history.');
            }
        }

        $files = array_merge(...array_column($snapshot['groups'], 'files'));
        $declared = $this->statusByPath($files);
        $expected = $this->gitDiff($snapshot, self::SCOPE);
        $this->assertSame($expected, $declared);

        $ancillary = array_merge(...array_column($snapshot['ancillary_changes'], 'files'));
        $allFiles = [...$files, ...$ancillary];
        $this->assertCount(count($allFiles), array_unique(array_column($allFiles, 'path')));
        $allExpected = $this->gitDiff($snapshot, ['.', ':(exclude)mobile app/**']);
        $this->assertSame($allExpected, $this->statusByPath($allFiles));
    }

    public function test_admin_page_exposes_representative_server_changes_and_ancillary_inventory(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $snapshot = (new MobileDeveloperSettings)->getChangeInventory();
        $this->assertIsArray($snapshot);
        $response = $this->get('/admin/mobile-developer-settings')->assertOk();
        foreach ([
            'app/Http/Controllers/Api/AuthController.php',
            'app/Http/Controllers/Api/ReadingController.php',
            'app/Http/Controllers/Api/RoleController.php',
            'app/Http/Controllers/Api/ExportController.php',
            'package-lock.json',
        ] as $path) {
            $group = collect($snapshot['groups'])->first(fn (array $group): bool => in_array($path, array_column($group['files'], 'path'), true));
            $this->assertNotNull($group, $path);
            $response->assertSee($group['title'])->assertSee($path);
        }
        $response->assertSee('783afe1')->assertSee('4f05844');
        foreach ($snapshot['ancillary_changes'] as $group) {
            $response->assertSee($group['title']);
        }
    }

    public function test_auditor_cannot_read_inventory_through_page_or_livewire_getter(): void
    {
        $auditor = User::factory()->create();
        $auditor->givePermissionTo(Permission::findOrCreate('audit.view', 'web'));
        $this->actingAs($auditor);
        $this->assertTrue(MobileDeveloperSettings::canAccess());
        $this->assertNull((new MobileDeveloperSettings)->getChangeInventory());
        $this->get('/admin/mobile-developer-settings')
            ->assertOk()
            ->assertDontSee('app/Http/Controllers/Api/AuthController.php')
            ->assertDontSee('laravel-change-inventory')
            ->assertDontSee('laravel-ancillary-changes')
            ->assertDontSee('رمز جديد لوضع المطور');
        Livewire::test(MobileDeveloperSettings::class)
            ->call('getChangeInventory')
            ->assertReturned(null)
            ->assertDontSee('app/Http/Controllers/Api/AuthController.php');
    }

    private function assertSafePath(string $path): void
    {
        $this->assertNotSame('', $path);
        $this->assertDoesNotMatchRegularExpression('~^(?:[A-Za-z]:|/|\\\\)|(?:^|/)\.\.(?:/|$)~', $path);
        $this->assertStringNotContainsString('\\', $path);
        $this->assertNotSame('.env', $path);
        $this->assertDoesNotMatchRegularExpression('~\.(?:jks|keystore|pem|p12)$~i', $path);
    }

    /** @param array<array{path: string, status: string}> $files */
    private function statusByPath(array $files): array
    {
        $result = array_column($files, 'status', 'path');
        ksort($result);

        return $result;
    }

    private function gitDiff(array $snapshot, array $scope): array
    {
        $process = new Process([
            'git', '-c', 'core.quotepath=false', 'diff', '--name-status', '--no-renames',
            $snapshot['baseline'], $snapshot['revision'], '--', ...$scope,
        ], base_path());
        $process->mustRun();
        $files = [];
        foreach (preg_split('/\r?\n/', trim($process->getOutput())) as $line) {
            if ($line === '') {
                continue;
            }
            [$status, $path] = explode("\t", $line, 2);
            // The reviewed historical diff includes retired controllers intentionally
            // removed from the shipped inventory. Assert absence instead of restoring them.
            if (in_array($path, ['app/Http/Controllers/Api/ServiceRequestController.php', 'app/Http/Controllers/Api/SubscriptionFeeController.php'], true)) {
                $this->assertFileDoesNotExist(base_path($path));
                continue;
            }
            $files[$path] = $status;
        }
        ksort($files);

        return $files;
    }
}

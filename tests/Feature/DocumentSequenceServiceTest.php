<?php

namespace Tests\Feature;

use App\Services\DocumentSequenceService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OverflowException;
use RuntimeException;
use Tests\TestCase;

class DocumentSequenceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        if (! app()->environment('testing') || ! (
            ($db->getDriverName() === 'sqlite' && $db->getDatabaseName() === ':memory:')
            || ($db->getDriverName() === 'mysql' && $db->getDatabaseName() === 'water_team_b_acc14_test')
        )) {
            $this->markTestSkipped('Requires dedicated ACC-14 test database.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    private function service(): DocumentSequenceService
    {
        return app(DocumentSequenceService::class);
    }

    public function test_independent_kind_and_month_and_persistent_monotonic_numbers(): void
    {
        $this->assertSame('INV-202611-0001', $this->service()->next('INV', '202611'));
        $this->assertSame('INV-202611-0002', $this->service()->next('INV', '202611'));
        $this->assertSame('REC-202611-0001', $this->service()->next('REC', '202611'));
        $this->assertSame('INV-202612-0001', $this->service()->next('INV', '202612'));
        $this->assertSame('INV-202611-0003', $this->service()->next('INV', '202611'));
        $this->assertSame(3, DB::table('document_sequences')->count());
    }

    public function test_legacy_numeric_max_includes_soft_deleted_and_lowercase_numbers(): void
    {
        $square = \App\Models\Square::create(['name' => 'Sequence test']);
        $subscriber = $square->subscribers()->create(['name' => 'Sequence test', 'status' => 'active']);
        foreach (['INV-202611-9999', 'inv-202611-10000', 'INV-202611-manual', 'INV-202610-50000'] as $number) {
            $invoice = new \App\Models\Invoice;
            $invoice->forceFill(['subscriber_id' => $subscriber->id, 'bill_number' => $number,
                'bill_date' => '2026-11-01', 'status' => 'issued',
                'deleted_at' => str_contains($number, '10000') ? now() : null])->saveQuietly();
        }
        $this->assertSame('INV-202611-10001', $this->service()->next('INV', '202611'));
        $this->assertSame('INV-202611-10002', $this->service()->next('INV', '202611'));
        $this->assertSame(4, DB::table('invoices')->count());
    }

    public function test_rollback_releases_unpublished_reservation_and_commit_persists_it(): void
    {
        try {
            DB::transaction(function (): void {
                $this->assertSame('REC-202611-0001', $this->service()->next('REC', '202611'));
                throw new RuntimeException('rollback fixture');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('rollback fixture', $e->getMessage());
        }
        $this->assertSame(0, DB::table('document_sequences')->count());
        $this->assertSame('REC-202611-0001', $this->service()->next('REC', '202611'));
        $this->assertSame('REC-202611-0002', DB::transaction(fn () => $this->service()->next('REC', '202611')));
        $this->assertSame('REC-202611-0003', $this->service()->next('REC', '202611'));
    }

    public function test_rejects_invalid_kind_and_month_without_writing(): void
    {
        foreach ([['BAD','202611'], ['INV','202600'], ['INV','202613'], ['INV','000011'], ['INV','202611 ']] as [$kind,$month]) {
            try {
                $this->service()->next($kind, $month);
                $this->fail('Expected invalid argument');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, DB::table('document_sequences')->count());
            }
        }
    }

    public function test_exhaustion_is_fail_closed(): void
    {
        DB::table('document_sequences')->insert(['kind' => 'INV', 'period' => '202611', 'last_number' => 999999999999999]);
        try {
            $this->service()->next('INV', '202611');
            $this->fail('Expected sequence overflow');
        } catch (OverflowException) {
            $this->assertSame('999999999999999', (string) DB::table('document_sequences')->value('last_number'));
        }
    }

    public function test_mysql_concurrent_first_use_and_allocation(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real row locks tested on MySQL only.');
        }
        $this->assertTrue(function_exists('pcntl_fork') && function_exists('posix_kill'));
        $dir = sys_get_temp_dir().'/acc14-race-'.bin2hex(random_bytes(12));
        mkdir($dir, 0700);
        DB::disconnect();
        $children = [];
        try {
            for ($i = 0; $i < 4; $i++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('fork failed');
                }
                if ($pid === 0) {
                    DB::purge();
                    touch("$dir/ready-$i");
                    $deadline = microtime(true) + 15;
                    while (! is_file("$dir/go")) {
                        if (microtime(true) > $deadline) exit(2);
                        usleep(1000);
                    }
                    try {
                        $numbers = [];
                        for ($j = 0; $j < 10; $j++) {
                            $numbers[] = $this->service()->next('INV', '202611');
                        }
                        file_put_contents("$dir/result-$i", json_encode($numbers, JSON_THROW_ON_ERROR));
                        DB::disconnect();
                        exit(0);
                    } catch (\Throwable $e) {
                        file_put_contents("$dir/error-$i", $e->getMessage());
                        exit(3);
                    }
                }
                $children[$pid] = $i;
            }
            $deadline = microtime(true) + 15;
            while (count(glob("$dir/ready-*")) !== 4) {
                if (microtime(true) > $deadline) throw new RuntimeException('workers not ready');
                usleep(1000);
            }
            touch("$dir/go");
            $deadline = microtime(true) + 45;
            while ($children !== []) {
                foreach ($children as $pid => $i) {
                    $result = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($result === $pid) {
                        unset($children[$pid]);
                        $this->assertTrue(pcntl_wifexited($status));
                        $this->assertSame(0, pcntl_wexitstatus($status), is_file("$dir/error-$i") ? file_get_contents("$dir/error-$i") : '');
                    } elseif ($result === -1) {
                        throw new RuntimeException('waitpid failed');
                    }
                }
                if (microtime(true) > $deadline) throw new RuntimeException('worker timeout');
                usleep(10000);
            }
            $numbers = [];
            for ($i = 0; $i < 4; $i++) {
                $numbers = array_merge($numbers, json_decode(file_get_contents("$dir/result-$i"), true, 512, JSON_THROW_ON_ERROR));
            }
            sort($numbers);
            $this->assertSame(array_map(fn ($i) => sprintf('INV-202611-%04d', $i), range(1, 40)), $numbers);
            DB::purge();
            $this->assertSame('INV-202611-0041', $this->service()->next('INV', '202611'));
            $this->assertSame(1, DB::table('document_sequences')->count());
        } finally {
            foreach ($children as $pid => $i) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            foreach (glob("$dir/*") as $file) unlink($file);
            rmdir($dir);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FinancialCloseCoordinator;
use App\Services\FinancialCloseProofSource;
use App\Services\FinancialWriteGate;
use App\Support\FinancialClosePolicy;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Synthetic proof source only. No production container binding or real coverage claim. */
final class FinancialCloseCoordinatorTest extends TestCase
{
    private const FREEZE = '11111111-1111-4111-8111-111111111111';
    private const PREPARE = '22222222-2222-4222-8222-222222222222';
    private const APPROVE = '33333333-3333-4333-8333-333333333333';
    private const ABORT = '44444444-4444-4444-8444-444444444444';
    private FinancialCloseProofSource $proof;
    private FinancialCloseCoordinator $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || ! (
            (DB::getDriverName() === 'sqlite' && DB::getDatabaseName() === ':memory:')
            || (DB::getDriverName() === 'mysql' && DB::getDatabaseName() === 'water_test')
        )) {
            $this->markTestSkipped('Requires disposable isolated close-command database.');
        }
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        DB::table('financial_scopes')->insert([
            'scope_key' => 'water', 'currency' => 'YER', 'timezone' => 'Asia/Aden',
            'mode' => 'ACTIVE', 'calendar_version' => 1, 'fence_epoch' => 10,
        ]);
        foreach (['2026-11' => '2026-12-01', '2026-12' => '2027-01-01'] as $month => $next) {
            DB::table('financial_periods')->insert([
                'scope_key' => 'water', 'month' => $month, 'start_on' => $month.'-01',
                'next_on' => $next, 'state' => 'OPEN', 'version' => 0,
            ]);
        }
        $this->proof = new class implements FinancialCloseProofSource {
            public bool $enabled = true;
            public bool $coverage = true;
            public bool $replay = true;

            public function authorize(string $scope, string $capability, int $actorId): bool
            {
                return $this->enabled && $scope === 'water' && in_array($actorId, [1, 2, 3], true);
            }

            public function evidence(string $action, array $period, int $actorId): array
            {
                $h = str_repeat('a', 64);
                $m = str_repeat('b', 64);
                $facts = [
                    'predecessor_verified' => true, 'predecessor_hash' => $h, 'writers_drained' => true,
                    'coverage_verified' => $this->coverage, 'no_unexplained_variance' => true,
                    'carry_evidence_verified' => true, 'manifest_hash' => $m,
                    'snapshot_verified' => true, 'snapshot_hash' => $period['attempt']['prepared']['hash'] ?? null,
                    'persisted_effects_verified' => $this->replay,
                ];
                return ['facts' => $facts, 'snapshot' => [
                    'manifest_hash' => $m,
                    'coverage' => array_fill_keys(['allocation', 'bank', 'cash', 'custody', 'liability', 'recoverable', 'subscriber', 'transit'], true),
                    'rows' => [
                        ['sector' => 'subscriber', 'key' => 'S1', 'opening' => -10025, 'movement' => 0, 'closing' => -10025, 'source_hash' => $h],
                        ['sector' => 'subscriber', 'key' => 'S2', 'opening' => 10025, 'movement' => 0, 'closing' => 10025, 'source_hash' => $h],
                        ['sector' => 'transit', 'key' => 'T1', 'opening' => 0, 'movement' => 101, 'closing' => 101, 'source_hash' => $h],
                    ],
                    'carries' => [['id' => 'C1', 'sector' => 'transit', 'row_key' => 'T1', 'amount' => 101,
                        'owner' => 5, 'due_on' => '2026-12-10', 'evidence_hash' => $h]],
                ]];
            }
        };
        $this->coordinator = new FinancialCloseCoordinator(new FinancialClosePolicy(), $this->proof);
    }

    private function command(string $action, int $actor, array $command): array
    {
        return $this->coordinator->execute('water', '2026-11', $action, $actor, $command,
            new DateTimeImmutable('2026-12-01T00:00:00+03:00'));
    }

    private function freeze(): array
    {
        return $this->command('freeze', 1, ['uuid' => self::FREEZE, 'version' => 0, 'reason' => 'Monthly close request']);
    }

    private function prepare(): array
    {
        return $this->command('prepare', 2, ['uuid' => self::PREPARE, 'version' => 1, 'attempt_uuid' => self::FREEZE]);
    }

    private function approval(string $hash): array
    {
        return ['uuid' => self::APPROVE, 'version' => 2, 'attempt_uuid' => self::FREEZE, 'snapshot_hash' => $hash];
    }

    private function rejects(string $reason, callable $run): void
    {
        try {
            $run();
            $this->fail('Unexpectedly accepted '.$reason);
        } catch (DomainException $e) {
            $this->assertSame($reason, $e->getMessage());
        }
    }

    public function test_freeze_prepare_approve_persists_exact_snapshot_and_replays_without_new_effects(): void
    {
        $freeze = $this->freeze();
        $this->assertSame('FROZEN', $freeze['current_state']);
        $this->assertSame(self::FREEZE, DB::table('financial_scopes')->value('active_freeze_uuid'));
        $this->assertSame('11', (string) DB::table('financial_scopes')->value('fence_epoch'));
        $this->assertTrue($this->freeze()['replayed']);
        $gate = app(FinancialWriteGate::class);
        $now = new DateTimeImmutable('2026-12-01T00:00:00+03:00');
        $this->rejects('financial_scope_not_active', fn () => $gate->withinOpenPeriod('water', '2026-12-01', $now, fn () => true));

        $prepared = $this->prepare();
        $hash = $prepared['result']['attempt']['prepared']['hash'];
        $this->assertSame('10025', $prepared['result']['attempt']['prepared']['snapshot']['subscriber_debt']);
        $this->assertSame('10025', $prepared['result']['attempt']['prepared']['snapshot']['subscriber_credit']);
        $this->rejects('self_approval', fn () => $this->command('approve', 2, $this->approval($hash)));
        $closed = $this->command('approve', 3, $this->approval($hash));
        $this->assertSame('CLOSED', $closed['current_state']);
        $this->assertSame('12', (string) DB::table('financial_scopes')->value('fence_epoch'));
        $this->assertNull(DB::table('financial_scopes')->value('active_freeze_uuid'));
        $snapshot = DB::table('financial_period_closures')->first();
        $this->assertSame($hash, $snapshot->snapshot_hash);
        $this->assertSame(3, DB::table('financial_close_commands')->count());
        $this->assertSame(1, DB::table('financial_period_closures')->count());
        $this->assertTrue($this->command('approve', 3, $this->approval($hash))['replayed']);
        $this->assertEquals($snapshot, DB::table('financial_period_closures')->first());
        $this->assertSame(3, DB::table('financial_close_commands')->count());
        $this->rejects('financial_period_not_open', fn () => $gate->withinOpenPeriod('water', '2026-11-30', $now, fn () => true));
        $this->assertTrue($gate->withinOpenPeriod('water', '2026-12-01', $now, fn () => true));
        $this->rejects('close_replay_conflict', fn () => $this->command('approve', 3, $this->approval(str_repeat('f', 64))));
        $this->proof->replay = false;
        $this->rejects('close_replay_effects_unverified', fn () => $this->command('approve', 3, $this->approval($hash)));
    }

    public function test_incomplete_proof_cannot_prepare_and_abort_releases_only_with_independent_actor(): void
    {
        $this->freeze();
        $this->proof->coverage = false;
        $this->rejects('incomplete_coverage', fn () => $this->prepare());
        $this->assertSame(1, DB::table('financial_close_commands')->count());
        $abort = ['uuid' => self::ABORT, 'version' => 1, 'attempt_uuid' => self::FREEZE, 'reason' => 'Independent abort reason'];
        $this->rejects('self_approval', fn () => $this->command('abort', 1, $abort));
        $this->proof->enabled = false;
        $this->rejects('close_unauthorized', fn () => $this->command('abort', 3, $abort));
        $this->proof->enabled = true;
        $result = $this->command('abort', 3, $abort);
        $this->assertSame('OPEN', $result['current_state']);
        $this->assertSame(self::FREEZE, $result['result']['aborted'][0]['attempt']['uuid']);
        $this->assertSame(2, DB::table('financial_close_commands')->count());
        $this->assertSame(0, DB::table('financial_period_closures')->count());
        $this->assertNull(DB::table('financial_scopes')->value('active_freeze_uuid'));
        $this->assertTrue($this->command('abort', 3, $abort)['replayed']);
    }

    public function test_approval_database_failure_rolls_back_journal_snapshot_and_fence_then_retries(): void
    {
        $this->freeze();
        $prepared = $this->prepare();
        $approval = $this->approval($prepared['result']['attempt']['prepared']['hash']);
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail): void {
            if ($fail && str_contains(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'financial_period_closures')) {
                throw new \RuntimeException('synthetic post-insert failure');
            }
        });
        try {
            $this->command('approve', 3, $approval);
            $this->fail('Expected synthetic failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic post-insert failure', $e->getMessage());
        } finally {
            $fail = false;
        }
        $this->assertSame(2, DB::table('financial_close_commands')->count());
        $this->assertSame(0, DB::table('financial_period_closures')->count());
        $this->assertSame('FROZEN', DB::table('financial_periods')->where('month', '2026-11')->value('state'));
        $this->assertSame(self::FREEZE, DB::table('financial_scopes')->value('active_freeze_uuid'));
        $this->assertSame('CLOSED', $this->command('approve', 3, $approval)['current_state']);
    }

    public function test_corrupt_journal_blocks_further_commands_and_no_production_proof_is_bound(): void
    {
        $this->assertFalse(app()->bound(FinancialCloseProofSource::class));
        $this->freeze();
        DB::table('financial_close_commands')->where('command_uuid', self::FREEZE)
            ->update(['result_state_hash' => str_repeat('0', 64)]);
        $this->rejects('close_journal_hash', fn () => $this->prepare());
        $this->assertSame(1, DB::table('financial_close_commands')->count());
        $this->assertSame('FROZEN', DB::table('financial_periods')->where('month', '2026-11')->value('state'));
    }
}

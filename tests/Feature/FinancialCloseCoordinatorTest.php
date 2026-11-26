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
            public ?string $predecessorHash = null;
            public ?array $nextRows = null;
            public ?array $nextCarries = null;

            public function authorize(string $scope, string $capability, int $actorId): bool
            {
                return $this->enabled && $scope === 'water' && in_array($actorId, [1, 2, 3], true);
            }

            public function evidence(string $action, array $period, int $actorId): array
            {
                $h = str_repeat('a', 64);
                $m = str_repeat('b', 64);
                $facts = [
                    'predecessor_verified' => true, 'predecessor_hash' => $this->predecessorHash ?? $h, 'writers_drained' => true,
                    'coverage_verified' => $this->coverage, 'no_unexplained_variance' => true,
                    'carry_evidence_verified' => true, 'manifest_hash' => $m,
                    'snapshot_verified' => true, 'snapshot_hash' => $period['attempt']['prepared']['hash'] ?? null,
                    'persisted_effects_verified' => $this->replay,
                ];
                return ['facts' => $facts, 'snapshot' => [
                    'manifest_hash' => $m,
                    'coverage' => array_fill_keys(['allocation', 'bank', 'cash', 'custody', 'liability', 'recoverable', 'subscriber', 'transit'], true),
                    'rows' => $this->nextRows ?? [
                        ['sector' => 'subscriber', 'key' => 'S1', 'opening' => -10025, 'movement' => 0, 'closing' => -10025, 'source_hash' => $h],
                        ['sector' => 'subscriber', 'key' => 'S2', 'opening' => 10025, 'movement' => 0, 'closing' => 10025, 'source_hash' => $h],
                        ['sector' => 'transit', 'key' => 'T1', 'opening' => 0, 'movement' => 101, 'closing' => 101, 'source_hash' => $h],
                    ],
                    'carries' => $this->nextCarries ?? [['id' => 'C1', 'sector' => 'transit', 'row_key' => 'T1', 'amount' => 101,
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

    private function corruptJournalForDiagnostic(): void
    {
        // Simulate a privileged storage failure in the disposable test DB only.
        DB::unprepared('DROP TRIGGER close_cmd_reject_update');
        try {
            DB::table('financial_close_commands')->where('command_uuid', self::FREEZE)
                ->update(['result_state_hash' => str_repeat('0', 64)]);
        } finally {
            app(\App\Services\FinancialCloseJournalProtection::class)->install();
        }
    }

    private function rejectsDirectCloseMutation(callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('Close evidence unexpectedly mutable.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('Financial close evidence is immutable', $error->getMessage());
        }
    }

    public function test_permanent_close_journal_direct_mutations_and_replacements_are_rejected(): void
    {
        $protection = app(\App\Services\FinancialCloseJournalProtection::class);
        $this->assertTrue($protection->active());
        $this->freeze();
        $prepared = $this->prepare();
        $approval = $this->approval($prepared['result']['attempt']['prepared']['hash']);
        $this->assertSame('CLOSED', $this->command('approve', 3, $approval)['current_state']);
        $period = DB::table('financial_periods')->where('month', '2026-11')->sole();
        $id = $period->id;
        $tables = ['financial_scopes', 'financial_periods', 'financial_close_commands', 'financial_period_closures'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        $command = DB::table('financial_close_commands')->where('command_uuid', self::FREEZE)->sole();
        $closure = DB::table('financial_period_closures')->where('period_id', $id)->sole();
        foreach ([
            fn () => DB::table('financial_close_commands')->where('command_uuid', self::FREEZE)->update(['result_state_hash' => str_repeat('0', 64)]),
            fn () => DB::table('financial_close_commands')->where('command_uuid', self::FREEZE)->delete(),
            fn () => DB::table('financial_period_closures')->where('period_id', $id)->update(['closure_hash' => str_repeat('0', 64)]),
            fn () => DB::table('financial_period_closures')->where('period_id', $id)->delete(),
            fn () => DB::table('financial_periods')->where('id', $id)->update(['state' => 'OPEN']),
            fn () => DB::table('financial_periods')->where('id', $id)->update(['version' => 0]),
            fn () => DB::table('financial_periods')->where('id', $id)->update(['month' => '2026-10']),
            fn () => DB::table('financial_periods')->where('id', $id)->delete(),
        ] as $attempt) {
            $this->rejectsDirectCloseMutation($attempt);
            $this->assertEquals($before, $snapshot());
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach ([
                ['financial_close_commands', (array) $command],
                ['financial_close_commands', array_replace((array) $command, ['command_uuid' => self::ABORT])],
                ['financial_period_closures', (array) $closure],
                ['financial_periods', array_replace((array) $period, ['state' => 'OPEN'])],
                ['financial_periods', array_replace((array) $period, ['id' => 99, 'state' => 'OPEN'])],
            ] as [$table, $row]) {
                $columns = implode(', ', array_keys($row));
                $parameters = implode(', ', array_fill(0, count($row), '?'));
                $this->rejectsDirectCloseMutation(fn () =>
                    DB::statement("INSERT OR REPLACE INTO {$table} ({$columns}) VALUES ({$parameters})", array_values($row)));
                $this->assertEquals($before, $snapshot());
            }
        }
        $this->assertTrue($this->command('approve', 3, $approval)['replayed']);
        $this->assertEquals($before, $snapshot());
    }

    public function test_permanent_close_missing_protection_and_privileged_reopened_projection_fail_closed(): void
    {
        $protection = app(\App\Services\FinancialCloseJournalProtection::class);
        DB::unprepared('DROP TRIGGER close_cmd_reject_delete');
        try {
            $this->assertFalse($protection->active());
            $this->rejects('close_journal_protection_missing', fn () => $this->freeze());
            $this->assertSame(0, DB::table('financial_close_commands')->count());
        } finally {
            $protection->install();
        }
        $this->freeze();
        $prepared = $this->prepare();
        $this->command('approve', 3, $this->approval($prepared['result']['attempt']['prepared']['hash']));
        $id = DB::table('financial_periods')->where('month', '2026-11')->value('id');
        DB::unprepared('DROP TRIGGER close_period_reject_update');
        try {
            DB::table('financial_periods')->where('id', $id)->update(['state' => 'OPEN']);
            $called = false;
            $gate = app(FinancialWriteGate::class);
            $this->rejects('financial_period_permanently_closed', fn () => $gate->withinOpenPeriod(
                'water', '2026-11-25', new DateTimeImmutable('2026-12-01T00:00:00+03:00'),
                function () use (&$called) { $called = true; }));
            $this->assertFalse($called);
            $this->assertSame(3, DB::table('financial_close_commands')->count());
            $this->assertSame(1, DB::table('financial_period_closures')->count());
        } finally {
            // Restore the synthetic projection before reinstalling the guard.
            DB::table('financial_periods')->where('id', $id)->update(['state' => 'CLOSED']);
            $protection->install();
        }
        $this->assertTrue($protection->active());
    }

    public function test_close_refuses_commands_and_replays_without_ledger_protection(): void
    {
        $protection = app(\App\Services\LedgerProtection::class);
        $this->assertTrue($protection->active());
        $this->freeze();
        $scope = DB::table('financial_scopes')->where('scope_key', 'water')->first();
        $period = DB::table('financial_periods')->where('month', '2026-11')->first();
        DB::unprepared('DROP TRIGGER ledger_reject_delete');
        try {
            $this->assertFalse($protection->active());
            $this->rejects('close_ledger_protection_missing', fn () => $this->freeze());
            $this->rejects('close_ledger_protection_missing', fn () => $this->prepare());
            $this->assertEquals($scope, DB::table('financial_scopes')->where('scope_key', 'water')->first());
            $this->assertEquals($period, DB::table('financial_periods')->where('month', '2026-11')->first());
            $this->assertSame(1, DB::table('financial_close_commands')->count());
            $this->assertSame(0, DB::table('financial_period_closures')->count());
        } finally {
            $protection->install();
        }
        $this->assertTrue($protection->active());
        $this->assertTrue($this->freeze()['replayed']);
        $prepared = $this->prepare();
        $this->assertSame('CLOSED', $this->command('approve', 3, $this->approval($prepared['result']['attempt']['prepared']['hash']))['current_state']);
    }

    public function test_corrupt_journal_blocks_further_commands_and_no_production_proof_is_bound(): void
    {
        $this->assertFalse(app()->bound(FinancialCloseProofSource::class));
        $this->freeze();
        $this->corruptJournalForDiagnostic();
        $this->rejects('close_journal_hash', fn () => $this->prepare());
        $this->assertSame(1, DB::table('financial_close_commands')->count());
        $this->assertSame('FROZEN', DB::table('financial_periods')->where('month', '2026-11')->value('state'));
    }

    private function prepareFollowingPeriod(bool $shiftBalances = false, ?array $priorCarries = null, ?array $followingCarries = null): array
    {
        $this->proof->nextCarries = $priorCarries;
        $this->freeze();
        $prepared = $this->prepare();
        $closed = $this->command('approve', 3, $this->approval($prepared['result']['attempt']['prepared']['hash']));
        $this->proof->predecessorHash = $closed['result']['closure']['hash'];
        $h = str_repeat('a', 64);
        $this->proof->nextRows = [
            ['sector' => 'subscriber', 'key' => 'S1', 'opening' => $shiftBalances ? -10000 : -10025,
                'movement' => 0, 'closing' => $shiftBalances ? -10000 : -10025, 'source_hash' => $h],
            ['sector' => 'subscriber', 'key' => 'S2', 'opening' => $shiftBalances ? 10000 : 10025,
                'movement' => 0, 'closing' => $shiftBalances ? 10000 : 10025, 'source_hash' => $h],
            ['sector' => 'transit', 'key' => 'T1', 'opening' => 101, 'movement' => 0, 'closing' => 101, 'source_hash' => $h],
        ];
        $this->proof->nextCarries = $followingCarries ?? [['id' => 'C1', 'sector' => 'transit', 'row_key' => 'T1',
            'amount' => 101, 'owner' => 5, 'due_on' => '2027-01-10', 'evidence_hash' => $h]];
        $uuid = '55555555-5555-4555-8555-555555555555';
        $now = new DateTimeImmutable('2027-01-01T00:00:00+03:00');
        $this->coordinator->execute('water', '2026-12', 'freeze', 1, ['uuid' => $uuid, 'version' => 0,
            'reason' => 'Following period close request'], $now);
        $this->coordinator->execute('water', '2026-12', 'prepare', 2, [
            'uuid' => '66666666-6666-4666-8666-666666666666', 'version' => 1, 'attempt_uuid' => $uuid], $now);
        return $closed;
    }

    private function continuity(string $month): array
    {
        return DB::transaction(fn () => app(\App\Services\FinancialOpeningContinuityDiagnostic::class)->inspect(
            (int) DB::table('financial_periods')->where('month', $month)->value('id')));
    }

    public function test_round144_adjacent_openings_match_but_first_root_and_carry_are_unverified(): void
    {
        $closed = $this->prepareFollowingPeriod();
        $before = DB::table('financial_close_commands')->orderBy('period_id')->orderBy('result_version')->get()->toArray();
        $result = $this->continuity('2026-12');
        $this->assertTrue($result['adjacent_snapshots_match']);
        $this->assertSame($closed['result']['closure']['hash'], $result['predecessor_hash']);
        $this->assertSame(3, $result['matched_predecessor_rows']);
        $this->assertFalse($result['root_opening_verified']);
        $this->assertFalse($result['opening_source_verified']);
        $this->assertFalse($result['carry_continuity_verified']);
        $this->assertFalse($result['closure_authorized']);
        $first = $this->continuity('2026-11');
        $this->assertFalse($first['adjacent_snapshots_match']);
        $this->assertSame([['code' => 'independent_predecessor_required']], $first['issues']);
        $this->assertEquals($before, DB::table('financial_close_commands')->orderBy('period_id')->orderBy('result_version')->get()->toArray());
        $this->assertSame('FROZEN', DB::table('financial_periods')->where('month', '2026-12')->value('state'));
    }

    public function test_round144_offsetting_opening_mismatches_and_corrupt_prior_journal_are_detected(): void
    {
        $this->prepareFollowingPeriod(true);
        $result = $this->continuity('2026-12');
        $this->assertFalse($result['adjacent_snapshots_match']);
        $this->assertSame(1, $result['matched_predecessor_rows']);
        $this->assertContains(['code' => 'opening_balance_mismatch', 'row' => 'subscriber:S1',
            'expected_minor' => -10025, 'actual_minor' => -10000], $result['issues']);
        $this->assertContains(['code' => 'opening_balance_mismatch', 'row' => 'subscriber:S2',
            'expected_minor' => 10025, 'actual_minor' => 10000], $result['issues']);
        $this->assertFalse($result['closure_authorized']);
        $this->corruptJournalForDiagnostic();
        $this->rejects('close_journal_hash', fn () => $this->continuity('2026-12'));
    }

    private function carryFixture(string $id, int $amount, int $owner = 5, string $due = '2027-01-10', ?string $hash = null): array
    {
        return ['id' => $id, 'sector' => 'transit', 'row_key' => 'T1', 'amount' => $amount,
            'owner' => $owner, 'due_on' => $due, 'evidence_hash' => $hash ?? str_repeat('a', 64)];
    }

    public function test_round145_unchanged_carry_matches_structurally_without_certifying_underlying_evidence(): void
    {
        $carry = $this->carryFixture('C1', 101);
        $this->prepareFollowingPeriod(false, [$carry], [$carry]);
        $result = $this->continuity('2026-12');
        $this->assertTrue($result['adjacent_snapshots_match']);
        $this->assertTrue($result['carry_snapshots_unchanged']);
        $this->assertSame(1, $result['unchanged_carries']);
        $this->assertSame([], $result['carry_snapshot_issues']);
        $this->assertFalse($result['carry_continuity_verified']);
        $this->assertFalse($result['closure_authorized']);
    }

    public function test_round145_equal_total_does_not_hide_reassigned_removed_or_new_carries(): void
    {
        $prior = [$this->carryFixture('C1', 51), $this->carryFixture('C2', 50)];
        $after = [$this->carryFixture('C1', 50, 6, '2027-01-15', str_repeat('c', 64)),
            $this->carryFixture('C3', 51)];
        $this->prepareFollowingPeriod(false, $prior, $after);
        $result = $this->continuity('2026-12');
        $this->assertTrue($result['adjacent_snapshots_match']);
        $this->assertFalse($result['carry_snapshots_unchanged']);
        $this->assertSame(0, $result['unchanged_carries']);
        $this->assertSame([
            ['code' => 'carry_change_requires_proof', 'id' => 'C1', 'fields' => ['amount', 'owner', 'due_on', 'evidence_hash']],
            ['code' => 'carry_settlement_or_removal_requires_proof', 'id' => 'C2'],
            ['code' => 'new_carry_requires_proof', 'id' => 'C3'],
        ], $result['carry_snapshot_issues']);
        $this->assertFalse($result['carry_continuity_verified']);
        $this->assertFalse($result['closure_authorized']);
    }
}

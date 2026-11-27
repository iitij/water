<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FinancialPostingRegistryTest extends TestCase
{
    private const UUID = 'c4c1c84f-3ae4-4808-a2ad-78a484a6397d';
    private const OTHER = 'f1613591-9c6d-4efd-a4f2-0269eec25165';

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || ! (
            (DB::getDriverName() === 'sqlite' && DB::getDatabaseName() === ':memory:')
            || (DB::getDriverName() === 'mysql' && DB::getDatabaseName() === 'water_test')
        )) {
            $this->markTestSkipped('Requires disposable isolated financial registry database.');
        }
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        DB::table('financial_scopes')->insert([
            'scope_key' => 'water', 'currency' => 'YER', 'timezone' => 'Asia/Aden',
            'mode' => 'ACTIVE', 'calendar_version' => 1, 'fence_epoch' => 5,
        ]);
        DB::table('financial_periods')->insert([
            'scope_key' => 'water', 'month' => '2026-11', 'start_on' => '2026-11-01',
            'next_on' => '2026-12-01', 'state' => 'OPEN', 'version' => 2,
        ]);
    }

    private function nowAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-25T08:30:00.123456Z');
    }

    private function gate(callable $writer): mixed
    {
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', '2026-11-25', $this->nowAt(), $writer);
    }

    private function leg(string $id, string $leg, int $amount): array
    {
        return ['source_type' => 'payment', 'source_id' => $id, 'leg' => $leg,
            'amount_minor' => $amount, 'currency' => 'YER', 'source_hash' => hash('sha256', $id.$leg)];
    }

    private function record(array $context, string $uuid, array $legs): bool
    {
        return app(FinancialPostingRegistry::class)->record($context, $uuid, $legs, $this->nowAt());
    }

    private function corruptEffectForDiagnostic(): void
    {
        DB::unprepared('DROP TRIGGER fin_registry_leg_reject_update');
        try {
            DB::table('financial_posting_events')->update(['amount_minor' => 11]);
        } finally {
            app(\App\Services\FinancialPostingRegistryProtection::class)->install();
        }
    }

    private function rejectsRegistryMutation(callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('Registry mutation accepted.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Financial registry evidence is immutable or fenced', $error->getMessage());
        }
    }

    public function test_held_document_guard_detects_same_name_wrong_body_and_refuses_install(): void
    {
        $guard = app(\App\Services\HeldCashDocumentProtection::class);
        $this->assertTrue($guard->active());
        DB::unprepared('DROP TRIGGER held_assignment_reject_update');
        $body = DB::getDriverName() === 'sqlite' ? 'BEGIN SELECT 1; END' : 'FOR EACH ROW SET NEW.amount_minor = NEW.amount_minor';
        DB::unprepared('CREATE TRIGGER held_assignment_reject_update BEFORE UPDATE ON treasury_held_cash_assignments '.$body);
        try {
            $this->assertFalse($guard->active());
            $this->assertFalse(app(\App\Services\FinancialGuardInventory::class)->inspect()['held_documents']);
            try {
                $guard->install();
                $this->fail('Accepted wrong trigger body.');
            } catch (\LogicException $error) {
                $this->assertSame('Unexpected held cash protection: held_assignment_reject_update', $error->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER held_assignment_reject_update');
            $guard->install();
        }
        $this->assertTrue($guard->active());
        $this->assertTrue(app(\App\Services\FinancialGuardInventory::class)->inspect()['held_documents']);

        foreach ([
            [\App\Services\HeldRegistryLinkProtection::class, 'fin_held_link_held_cash_assignment', 'fin_held_link_held_cash_payout', 'treasury_held_cash_payouts'],
            [\App\Services\HeldCashDestinationProtection::class, 'fin_held_dest_treasury_held_cash_assignments_i', 'fin_held_dest_treasury_held_cash_payouts_i', 'treasury_held_cash_payouts'],
        ] as [$service, $missing, $wrong, $table]) {
            $protection = app($service);
            $this->assertTrue($protection->active());
            DB::unprepared('DROP TRIGGER '.$missing);
            DB::unprepared('DROP TRIGGER '.$wrong);
            $body = DB::getDriverName() === 'sqlite' ? 'BEGIN SELECT 1; END' : 'FOR EACH ROW SET NEW.amount_minor = NEW.amount_minor';
            DB::unprepared("CREATE TRIGGER {$wrong} BEFORE INSERT ON {$table} {$body}");
            try {
                $this->assertFalse($protection->active());
                try {
                    $protection->install();
                    $this->fail('Accepted conflicting trigger definition.');
                } catch (\LogicException $error) {
                    $this->assertStringContainsString($wrong, $error->getMessage());
                }
                $exists = DB::getDriverName() === 'sqlite'
                    ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', $missing)->exists()
                    : \App\Services\FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $missing)->isNotEmpty();
                $this->assertFalse($exists, 'Installation must not perform partial DDL.');
            } finally {
                DB::unprepared('DROP TRIGGER '.$wrong);
                $protection->install();
            }
            $this->assertTrue($protection->active());
        }
    }

    public function test_unknown_registry_source_is_reported_without_certifying_coverage(): void
    {
        $this->gate(fn (array $context) => $this->record($context, self::UUID, [$this->leg('10', 'bank', 10)]));
        $periodId = (int) DB::table('financial_periods')->sole()->id;
        $report = DB::transaction(fn () => app(\App\Services\FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $periodId));
        $this->assertSame(1, $report['events']);
        $this->assertSame(1, $report['other_registered_legs_unverified']);
        $this->assertSame([
            ['source_type' => 'payment', 'source_id' => '10', 'leg' => 'bank', 'reason' => 'unsupported_source_type'],
        ], $report['unverified_registered_sources']);
        $this->assertFalse($report['source_coverage_verified']);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_registry_rejects_nonexistent_financial_source_legs(): void
    {
        $this->gate(fn (array $context) => $this->record($context, self::UUID, [$this->leg('10', 'bank', 10)]));
        $before = DB::table('financial_posting_events')->orderBy('id')->get()->all();
        $row = (array) $before[0];
        unset($row['id']);
        foreach ([
            ['source_type' => 'ledger_transaction', 'leg' => 'subscriber'],
            ['source_type' => 'treasury_posting', 'leg' => 'treasury'],
            ['source_type' => 'treasury_opening', 'leg' => 'opening'],
        ] as $fields) {
            try {
                DB::table('financial_posting_events')->insert(array_replace($row, $fields, ['source_id' => '9223372036854775807']));
                $this->fail('Registry accepted a nonexistent financial source.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('Financial registry source identity is invalid', $error->getMessage());
            }
            $this->assertEquals($before, DB::table('financial_posting_events')->orderBy('id')->get()->all());
        }
        $this->assertDatabaseCount('financial_posting_operations', 1);
    }

    public function test_registry_protection_rejects_direct_mutation_and_fenced_inserts(): void
    {
        $this->assertTrue(app(\App\Services\FinancialPostingRegistryProtection::class)->active());
        $leg = $this->leg('10', 'bank', 10);
        $this->gate(fn (array $context) => $this->record($context, self::UUID, [$leg]));
        $operation = (array) DB::table('financial_posting_operations')->sole();
        $event = (array) DB::table('financial_posting_events')->sole();
        $snapshot = fn () => [DB::table('financial_posting_operations')->get()->toArray(),
            DB::table('financial_posting_events')->get()->toArray()];
        $before = $snapshot();
        foreach ([
            fn () => DB::table('financial_posting_operations')->update(['payload_hash' => str_repeat('0', 64)]),
            fn () => DB::table('financial_posting_events')->update(['amount_minor' => 11]),
            fn () => DB::table('financial_posting_events')->delete(),
            fn () => DB::table('financial_posting_operations')->delete(),
        ] as $attempt) {
            $this->rejectsRegistryMutation($attempt);
            $this->assertEquals($before, $snapshot());
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach ([
                ['financial_posting_operations', $operation],
                ['financial_posting_events', $event],
                ['financial_posting_events', array_replace($event, ['id' => 999999])],
            ] as [$table, $row]) {
                $sql = 'INSERT OR REPLACE INTO '.$table.' ('.implode(', ', array_keys($row)).') VALUES ('
                    .implode(', ', array_fill(0, count($row), '?')).')';
                $this->rejectsRegistryMutation(fn () => DB::statement($sql, array_values($row)));
                $this->assertEquals($before, $snapshot());
            }
        }
        $this->assertFalse($this->gate(fn (array $context) => $this->record($context, self::UUID, [$leg])));
        $newOperation = array_replace($operation, ['operation_uuid' => self::OTHER]);
        $newEvent = array_diff_key(array_replace($event, ['source_id' => '20']), ['id' => true]);
        foreach (['FROZEN', 'CLOSED'] as $state) {
            DB::table('financial_periods')->where('id', $operation['period_id'])->update(['state' => $state]);
            $this->rejectsRegistryMutation(fn () => DB::table('financial_posting_operations')->insert($newOperation));
            $this->rejectsRegistryMutation(fn () => DB::table('financial_posting_events')->insert($newEvent));
            $this->assertEquals($before, $snapshot());
        }
        $this->assertSame(1, DB::transaction(fn () => app(FinancialPostingRegistry::class)
            ->verifyPeriod('water', (int) $operation['period_id']))['events']);
    }

    public function test_mysql_registry_fence_uses_current_reads_and_scope_lock(): void
    {
        if (DB::getDriverName() !== 'mysql') $this->markTestSkipped('Requires two independent MySQL connections.');
        $leg = $this->leg('10', 'bank', 10);
        $this->gate(fn (array $context) => $this->record($context, self::UUID, [$leg]));
        $op = (array) DB::table('financial_posting_operations')->sole();
        $event = (array) DB::table('financial_posting_events')->sole();
        $newOp = array_replace($op, ['operation_uuid' => self::OTHER]);
        $newEvent = array_diff_key(array_replace($event, ['source_id' => '20']), ['id' => true]);
        $name = 'qa_registry_second';
        config(["database.connections.{$name}" => config('database.connections.mysql')]);
        $other = DB::connection($name);
        $primary = DB::connection();
        try {
            $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $other->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->assertNotSame((string) $primary->selectOne('SELECT CONNECTION_ID() AS id')->id,
                (string) $other->selectOne('SELECT CONNECTION_ID() AS id')->id);
            $primary->beginTransaction();
            try {
                $primary->table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->sole();
                foreach ([
                    fn () => $other->table('financial_posting_operations')->insert($newOp),
                    fn () => $other->table('financial_posting_events')->insert($newEvent),
                ] as $attempt) {
                    try {
                        $attempt();
                        $this->fail('Direct insert did not wait for the scope lock.');
                    } catch (QueryException $error) {
                        $this->assertSame(1205, (int) $error->errorInfo[1]);
                    }
                }
            } finally {
                $primary->rollBack();
            }

            $other->beginTransaction();
            try {
                $this->assertSame('OPEN', $other->table('financial_periods')->where('id', $op['period_id'])->value('state'));
                // Freeze commits on another connection while this transaction
                // retains its old consistent read snapshot.
                $primary->transaction(function () use ($primary, $op): void {
                    $primary->table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->sole();
                    $primary->table('financial_periods')->where('id', $op['period_id'])->lockForUpdate()->sole();
                    $primary->table('financial_periods')->where('id', $op['period_id'])->update(['state' => 'FROZEN', 'version' => 3]);
                    $primary->table('financial_scopes')->where('scope_key', 'water')
                        ->update(['fence_epoch' => 6, 'active_freeze_uuid' => self::OTHER]);
                });
                $this->assertSame('OPEN', $other->table('financial_periods')->where('id', $op['period_id'])->value('state'));
                $this->assertSame('FROZEN', $primary->table('financial_periods')->where('id', $op['period_id'])->value('state'));
                $this->rejectsRegistryMutation(fn () => $other->table('financial_posting_operations')->insert($newOp));
                $this->rejectsRegistryMutation(fn () => $other->table('financial_posting_events')->insert($newEvent));
            } finally {
                $other->rollBack();
            }
            $this->assertEquals([$op], $primary->table('financial_posting_operations')->get()->map(fn ($row) => (array) $row)->all());
            $this->assertEquals([$event], $primary->table('financial_posting_events')->get()->map(fn ($row) => (array) $row)->all());
        } finally {
            DB::purge($name);
        }
    }

    public function test_missing_registry_protection_blocks_record_and_verification(): void
    {
        $registry = app(FinancialPostingRegistry::class);
        $periodId = (int) DB::table('financial_periods')->value('id');
        DB::unprepared('DROP TRIGGER fin_registry_op_reject_delete');
        try {
            $this->assertFalse(app(\App\Services\FinancialPostingRegistryProtection::class)->active());
            foreach ([
                fn () => $this->gate(fn (array $context) => $this->record($context, self::UUID, [$this->leg('10', 'bank', 10)])),
                fn () => $this->gate(fn () => $registry->verifyPeriod('water', $periodId)),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail('Missing registry protection accepted.');
                } catch (DomainException $error) {
                    $this->assertSame('posting_registry_protection_missing', $error->getMessage());
                }
            }
        } finally {
            app(\App\Services\FinancialPostingRegistryProtection::class)->install();
        }
        $this->assertDatabaseCount('financial_posting_operations', 0);
        $this->assertDatabaseCount('financial_posting_events', 0);
    }

    public function test_exact_signed_legs_and_byte_identical_replay_are_persisted(): void
    {
        $legs = [$this->leg('10', 'subscriber', 1025), $this->leg('10', 'bank', -PHP_INT_MAX)];
        $this->assertTrue($this->gate(fn (array $context) => $this->record($context, self::UUID, $legs)));
        $original = DB::table('financial_posting_operations')->first();
        $this->assertSame(hash('sha256', $original->canonical_payload), $original->payload_hash);
        $this->assertSame('2026-11-25T08:30:00.123456Z', $original->recorded_at_utc);
        $payload = json_decode($original->canonical_payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame((string) -PHP_INT_MAX, $payload['legs'][0]['amount_minor']);
        $this->assertSame('1025', $payload['legs'][1]['amount_minor']);
        $this->assertSame((string) -PHP_INT_MAX, (string) DB::table('financial_posting_events')->where('leg', 'bank')->value('amount_minor'));
        $this->assertFalse($this->gate(fn (array $context) => $this->record($context, self::UUID, array_reverse($legs))));
        $this->assertEquals($original, DB::table('financial_posting_operations')->first());
        $this->assertSame(2, DB::table('financial_posting_events')->count());

        try {
            $this->gate(fn (array $context) => $this->record($context, self::UUID, [$this->leg('10', 'subscriber', 1026)]));
            $this->fail('Changed payload accepted for an existing operation.');
        } catch (DomainException $e) {
            $this->assertSame('posting_replay_conflict', $e->getMessage());
        }
        $this->assertSame(2, DB::table('financial_posting_events')->count());
    }

    public function test_duplicate_source_leg_rolls_back_new_operation_and_outer_rollback_removes_all_evidence(): void
    {
        $this->gate(fn (array $context) => $this->record($context, self::UUID, [$this->leg('10', 'subscriber', 100)]));
        $this->gate(function (array $context): void {
            try {
                $this->record($context, self::OTHER, [
                    $this->leg('01', 'bank', 10), $this->leg('10', 'subscriber', 100),
                ]);
                $this->fail('Source leg reused by another operation.');
            } catch (QueryException) {
                // Savepoint must remove even the preceding successful insert.
                $this->assertSame(1, DB::table('financial_posting_operations')->count());
                $this->assertSame(1, DB::table('financial_posting_events')->count());
            }
        });
        try {
            $this->gate(function (array $context): void {
                $this->assertTrue($this->record($context, self::OTHER, [$this->leg('20', 'bank', 101)]));
                throw new \RuntimeException('synthetic outer failure');
            });
            $this->fail('Expected outer rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic outer failure', $e->getMessage());
        }
        $this->assertFalse(DB::table('financial_posting_operations')->where('operation_uuid', self::OTHER)->exists());
        $this->assertFalse(DB::table('financial_posting_events')->where('source_id', '20')->exists());
        $this->assertSame(1, DB::table('financial_posting_events')->count());
    }

    public function test_financial_gate_rejects_disabled_session_constraints(): void
    {
        $guard = app(\App\Services\FinancialConnectionIntegrity::class);
        $this->assertTrue($guard->active());
        $pairs = DB::getDriverName() === 'sqlite'
            ? [['PRAGMA foreign_keys = OFF', 'PRAGMA foreign_keys = ON'],
                ['PRAGMA ignore_check_constraints = ON', 'PRAGMA ignore_check_constraints = OFF']]
            : [['SET SESSION foreign_key_checks = 0', 'SET SESSION foreign_key_checks = 1'],
                ['SET SESSION unique_checks = 0', 'SET SESSION unique_checks = 1']];
        foreach ($pairs as [$disable, $restore]) {
            DB::statement($disable);
            try {
                $this->assertFalse($guard->active());
                $called = false;
                try {
                    $this->gate(function () use (&$called): void { $called = true; });
                    $this->fail('Gate accepted disabled session constraints.');
                } catch (DomainException $error) {
                    $this->assertSame('financial_connection_constraints_disabled', $error->getMessage());
                }
                $this->assertFalse($called);
                $this->assertDatabaseCount('financial_posting_operations', 0);
                $this->assertDatabaseCount('financial_posting_events', 0);
            } finally {
                DB::statement($restore);
            }
            $this->assertTrue($guard->active());
        }
        // The second switch can be changed inside a transaction on both engines.
        [$disable, $restore] = $pairs[1];
        try {
            $this->gate(function (array $context) use ($disable): void {
                $this->assertTrue($this->record($context, self::UUID, [$this->leg('10', 'bank', 10)]));
                DB::statement($disable);
            });
            $this->fail('Gate committed after session constraints changed.');
        } catch (DomainException $error) {
            $this->assertSame('financial_connection_constraints_disabled', $error->getMessage());
        } finally {
            DB::statement($restore);
        }
        $this->assertDatabaseCount('financial_posting_operations', 0);
        $this->assertDatabaseCount('financial_posting_events', 0);
        $this->assertTrue($this->gate(fn (array $context) => $this->record($context, self::UUID, [$this->leg('10', 'bank', 10)])));
    }

    public function test_registry_requires_live_gate_and_exact_context(): void
    {
        $leg = $this->leg('10', 'bank', 10);
        $context = $this->gate(fn (array $context) => $context);
        $reject = function (callable $attempt, string $reason): void {
            try {
                $attempt();
                $this->fail('Registry accepted an unauthenticated gate context.');
            } catch (DomainException $error) {
                $this->assertSame($reason, $error->getMessage());
            }
            $this->assertDatabaseCount('financial_posting_operations', 0);
            $this->assertDatabaseCount('financial_posting_events', 0);
        };
        // A previously valid context cannot authorize another transaction.
        $reject(fn () => DB::transaction(fn () => $this->record($context, self::UUID, [$leg])),
            'posting_registry_requires_outer_gate');
        $this->gate(function (array $live) use ($leg, $reject): void {
            foreach ([
                ['effective_on' => '2026-11-24'],
                ['epoch' => $live['epoch'] - 1],
                ['version' => $live['version'] - 1],
                ['period_id' => $live['period_id'] + 1],
            ] as $changes) {
                $reject(fn () => $this->record(array_replace($live, $changes), self::UUID, [$leg]),
                    'posting_gate_context_mismatch');
            }
        });
        $this->assertTrue($this->gate(fn (array $live) => $this->record($live, self::UUID, [$leg])));
        $this->assertFalse($this->gate(fn (array $live) => $this->record($live, self::UUID, [$leg])));
        $this->assertDatabaseCount('financial_posting_operations', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_rejects_inexact_input_stale_context_and_corrupt_replay(): void
    {
        $leg = $this->leg('10', 'bank', 10);
        try {
            $this->record([], self::UUID, [$leg]);
            $this->fail('Outside-gate write accepted.');
        } catch (DomainException $e) {
            $this->assertSame('posting_registry_requires_outer_gate', $e->getMessage());
        }

        $this->gate(function (array $context) use ($leg): void {
            foreach ([
                [array_replace($context, ['epoch' => 4]), [$leg], 'posting_gate_context_mismatch'],
                [$context, [array_replace($leg, ['amount_minor' => 10.0])], 'posting_minor'],
                [$context, [array_replace($leg, ['amount_minor' => PHP_INT_MIN])], 'posting_minor'],
                [$context, [$leg, $leg], 'duplicate_posting_leg'],
            ] as [$candidate, $legs, $expected]) {
                try {
                    $this->record($candidate, self::UUID, $legs);
                    $this->fail('Invalid registry operation accepted.');
                } catch (DomainException $e) {
                    $this->assertSame($expected, $e->getMessage());
                }
            }
            $this->assertSame(0, DB::table('financial_posting_operations')->count());
        });
        $this->gate(fn (array $context) => $this->record($context, self::UUID, [$leg]));
        // Synthetic tampering probes detection, NOT a claim of DB immutability.
        $this->corruptEffectForDiagnostic();
        try {
            $this->gate(fn (array $context) => $this->record($context, self::UUID, [$leg]));
            $this->fail('Corrupt persisted effect treated as identical replay.');
        } catch (DomainException $e) {
            $this->assertSame('posting_effect_corrupt', $e->getMessage());
        }
        $this->assertSame(1, DB::table('financial_posting_operations')->count());
        $this->assertSame('11', (string) DB::table('financial_posting_events')->value('amount_minor'));
    }

    public function test_period_evidence_digest_is_deterministic_and_never_claims_source_coverage(): void
    {
        $registry = app(FinancialPostingRegistry::class);
        $period = (int) DB::table('financial_periods')->value('id');
        $read = fn () => $this->gate(fn (array $context) => $registry->verifyPeriod('water', $period));
        $empty = $read();
        $this->assertSame(0, $empty['operations']);
        $this->assertSame(0, $empty['events']);
        $this->assertFalse($empty['source_coverage_verified']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $empty['registry_hash']);
        $this->gate(fn (array $context) => $this->record($context, self::OTHER, [$this->leg('20', 'bank', -PHP_INT_MAX)]));
        $this->gate(fn (array $context) => $this->record($context, self::UUID, [
            $this->leg('10', 'subscriber', 1025), $this->leg('10', 'bank', 1025),
        ]));
        $complete = $read();
        $this->assertSame(2, $complete['operations']);
        $this->assertSame(3, $complete['events']);
        $this->assertNotSame($empty['registry_hash'], $complete['registry_hash']);
        $this->assertSame($complete, $read());
        $this->assertFalse($complete['source_coverage_verified']);
        $this->gate(fn (array $context) => $this->record($context, self::UUID, [
            $this->leg('10', 'bank', 1025), $this->leg('10', 'subscriber', 1025),
        ]));
        $this->assertSame($complete, $read());
        // Freeze advances the fence/version; old evidence remains valid.
        DB::table('financial_periods')->where('id', $period)->update(['state' => 'FROZEN', 'version' => 3]);
        DB::table('financial_scopes')->where('scope_key', 'water')->update(['fence_epoch' => 6, 'active_freeze_uuid' => self::OTHER]);
        $this->assertSame($complete, DB::transaction(fn () => $registry->verifyPeriod('water', $period)));
    }

    public function test_period_evidence_rejects_tampering_even_when_hash_column_is_unchanged(): void
    {
        $registry = app(FinancialPostingRegistry::class);
        $this->gate(fn (array $context) => $this->record($context, self::UUID, [$this->leg('10', 'bank', 10)]));
        $period = (int) DB::table('financial_periods')->value('id');
        $this->corruptEffectForDiagnostic();
        try {
            $this->gate(fn (array $context) => $registry->verifyPeriod('water', $period));
            $this->fail('Corrupt evidence accepted.');
        } catch (DomainException $e) {
            $this->assertSame('posting_evidence_corrupt', $e->getMessage());
        }
        $this->assertSame('11', (string) DB::table('financial_posting_events')->value('amount_minor'));
        try {
            $registry->verifyPeriod('water', $period);
            $this->fail('Ungated verification accepted.');
        } catch (DomainException $e) {
            $this->assertSame('posting_registry_requires_outer_gate', $e->getMessage());
        }
    }
}

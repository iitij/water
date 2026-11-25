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
                [array_replace($context, ['epoch' => 4]), [$leg], 'posting_scope_changed'],
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
        DB::table('financial_posting_events')->update(['amount_minor' => 11]);
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
        DB::table('financial_posting_events')->update(['amount_minor' => 11]);
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

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Meter;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\CashSessionService;
use App\Services\LateReceiptReviewService;
use App\Services\MeterDataPurger;
use App\Services\TransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Throwable;

/** QA-only: each method requires a separately provisioned EMPTY synthetic MySQL schema. */
final class CrossAccountPendingReviewLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('WATER_L01_ISOLATED') !== '1' || ! app()->environment('testing')
            || DB::connection()->getDriverName() !== 'mysql'
            || DB::connection()->getDatabaseName() !== 'water_test'
            || ! DB::connection()->getConfig('unix_socket')) {
            self::markTestIncomplete('L01 BLOCKED: explicit isolated socket-only MySQL water_test required.');
        }
        self::assertTrue(function_exists('pcntl_fork') && function_exists('posix_kill'));
        self::assertSame(0, DB::transactionLevel());
        self::assertSame([], DB::select('SHOW TABLES'), 'L01 requires an EMPTY disposable schema; never reset an existing database.');
        $version = DB::selectOne('SELECT VERSION() AS version, @@version_comment AS product');
        self::assertStringStartsWith('8.0.', $version->version);
        self::assertStringNotContainsStringIgnoringCase('MariaDB', $version->product);
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
    }

    public function test_l01_r_existing_review_on_independent_account(): void
    {
        $this->scenario('R');
    }

    public function test_l01_i_new_review_on_independent_account(): void
    {
        $this->scenario('I');
    }

    private function account(string $name): array
    {
        $square = Square::create(['name' => 'L01 '.$name]);
        $subscriber = Subscriber::create(['name' => 'L01 '.$name, 'square_id' => $square->id, 'status' => 'active']);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'L01-'.$name]);

        return ['subscriber' => (int) $subscriber->id, 'meter' => (int) $meter->id];
    }

    private function fixture(string $name, string $mode): array
    {
        $f = $this->account($name);
        $collector = User::factory()->create(['role' => 'admin']);
        $reviewer = User::factory()->create(['role' => 'admin']);
        $reviewer->assignRole(Role::findOrCreate('super_admin', 'web'));
        $cash = app(CashSessionService::class);
        $session = $cash->close($cash->open($collector), 0.0, 'L01 synthetic closed session');
        self::assertTrue($session->isClosed());
        self::assertNotNull($session->closed_at);
        self::assertNotSame($collector->id, $reviewer->id);
        self::assertTrue(app(LateReceiptReviewService::class)->canDecide($reviewer));
        $f += ['collector' => (int) $collector->id, 'reviewer' => (int) $reviewer->id, 'claim' => [
            'sync_uuid' => (string) Str::uuid(), 'cash_session_id' => (int) $session->id,
            'subscriber_id' => $f['subscriber'], 'meter_id' => $f['meter'], 'amount' => '20.01',
            'payment_date' => $session->session_date->toDateString(), 'payment_method' => 'cash',
        ]];
        if ($mode === 'R') {
            $row = app(LateReceiptReviewService::class)->submit($collector, $f['claim']);
            self::assertSame('pending', $row['status']);
            $f['review'] = (int) $row['id'];
        }

        return $f;
    }

    private function operation(string $mode, array $f): array
    {
        $service = app(LateReceiptReviewService::class);
        return $mode === 'R'
            ? $service->decide($f['review'], User::findOrFail($f['reviewer']), [
                'approve' => false, 'evidence' => 'L01 independent synthetic rejection evidence',
            ])
            : $service->submit(User::findOrFail($f['collector']), $f['claim']);
    }

    private function snapshot(): array
    {
        $out = [];
        foreach (['subscribers', 'meters', 'readings', 'invoices', 'invoice_items', 'invoice_tariff_lines',
            'payments', 'transactions', 'payment_allocations', 'payment_allocation_revisions',
            'payment_refunds', 'payment_account_transfers', 'cash_sessions', 'cash_handovers',
            'treasury_accounts', 'treasury_postings', 'treasury_transfers', 'treasury_transfer_receipts',
            'treasury_cash_counts', 'treasury_cash_count_reviews', 'treasury_cash_adjustments',
            'bank_statements', 'bank_statement_lines', 'bank_statement_matches', 'treasury_bank_fees',
            'treasury_bank_collections', 'closed_cash_corrections',
            'noncash_collection_claims', 'late_receipt_reviews', 'audit_events'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $out;
    }

    private function assertOutcome(string $mode, array $f, array $before): void
    {
        $after = $this->snapshot();
        $reviews = $after['late_receipt_reviews'];
        $audit = $after['audit_events'];
        unset($after['late_receipt_reviews'], $after['audit_events']);
        $oldReviews = $before['late_receipt_reviews'];
        $oldAudit = $before['audit_events'];
        unset($before['late_receipt_reviews'], $before['audit_events']);
        self::assertSame($before, $after, 'L01 INVARIANT: unrelated financial or operational rows changed.');
        $matches = array_values(array_filter($reviews, fn ($r) => $r['sync_uuid'] === $f['claim']['sync_uuid']));
        self::assertCount(1, $matches);
        $row = $matches[0];
        self::assertSame($f['subscriber'], (int) $row['subscriber_id']);
        self::assertSame($f['meter'], (int) $row['submitted_meter_id']);
        self::assertSame($f['collector'], (int) $row['collector_id']);
        self::assertSame($f['claim']['cash_session_id'], (int) $row['cash_session_id']);
        self::assertSame('20.01', (string) $row['amount']);
        self::assertNull($row['payment_id']);
        self::assertSame($mode === 'R' ? 'rejected' : 'pending', $row['status']);
        if ($mode === 'R') {
            self::assertSame($f['reviewer'], (int) $row['decided_by']);
            self::assertSame('L01 independent synthetic rejection evidence', $row['evidence']);
            self::assertNotNull($row['decided_at']);
            $allowed = array_flip(['status', 'decided_by', 'decided_at', 'evidence', 'updated_at']);
            $oldRow = collect($oldReviews)->firstWhere('id', $row['id']);
            self::assertSame(array_diff_key($oldRow, $allowed), array_diff_key($row, $allowed));
        } else {
            self::assertNull($row['decided_by']);
        }
        $without = fn ($rows) => array_values(array_filter($rows, fn ($r) => $r['sync_uuid'] !== $f['claim']['sync_uuid']));
        self::assertSame($without($oldReviews), $without($reviews));
        self::assertCount(count($oldAudit) + 1, $audit);
        self::assertSame($oldAudit, array_slice($audit, 0, count($oldAudit)));
        $last = $audit[array_key_last($audit)];
        self::assertSame($mode === 'R' ? 'late_receipt.rejected' : 'late_receipt.submitted', $last['event']);
        self::assertSame('late_receipt_review', $last['entity_type']);
        self::assertSame((string) $row['id'], (string) $last['entity_id']);
        self::assertSame($mode === 'R' ? $f['reviewer'] : $f['collector'], (int) $last['actor_user_id']);
    }

    private static function mark(string $dir, string $name, array $data = []): void
    {
        $data = ['ns' => hrtime(true)] + $data;
        file_put_contents($dir.'/'.$name.'.tmp', json_encode($data, JSON_THROW_ON_ERROR));
        rename($dir.'/'.$name.'.tmp', $dir.'/'.$name);
    }

    private static function read(string $dir, string $name): ?array
    {
        $path = $dir.'/'.$name;
        return is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : null;
    }

    private static function await(string $dir, string $name, float $seconds): array
    {
        $until = hrtime(true) + (int) ($seconds * 1e9);
        do {
            if (($value = self::read($dir, $name)) !== null) {
                return $value;
            }
            usleep(10000);
        } while (hrtime(true) < $until);
        throw new \RuntimeException('L01 INCONCLUSIVE: missing marker '.$name);
    }

    private function fork(string $dir, string $name, callable $work): int
    {
        $pid = pcntl_fork();
        if ($pid < 0) {
            throw new \RuntimeException('L01 BLOCKED: fork failed');
        }
        if ($pid === 0) {
            DB::purge();
            try {
                DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $id = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
                self::mark($dir, $name.'-ready', ['connection' => $id]);
                $work();
                self::mark($dir, $name.'-result', ['ok' => true]);
            } catch (Throwable $e) {
                self::mark($dir, $name.'-result', [
                    'ok' => false, 'class' => get_class($e), 'message' => $e->getMessage(),
                    'sqlstate' => $e instanceof \Illuminate\Database\QueryException ? ($e->errorInfo[0] ?? null) : null,
                    'driver_code' => $e instanceof \Illuminate\Database\QueryException ? ($e->errorInfo[1] ?? null) : null,
                    'sql' => $e instanceof \Illuminate\Database\QueryException ? $e->getSql() : null,
                ]);
            } finally {
                DB::disconnect();
            }
            exit(0);
        }

        return $pid;
    }

    /** Optional existing read-only diagnostic permission only: no grants, fallback probes or retries. */
    private function waitEvidence(int $a, int $b): array
    {
        if (getenv('WATER_L01_READONLY_DIAGNOSTICS') !== '1') {
            return ['available' => false, 'reason' => 'not pre-authorized', 'rows' => []];
        }
        try {
            $rows = DB::select(
                'SELECT r.OBJECT_SCHEMA AS schema_name, r.OBJECT_NAME AS table_name,
                        r.INDEX_NAME AS index_name, r.LOCK_MODE AS requesting_mode,
                        k.LOCK_MODE AS blocking_mode, r.LOCK_DATA AS requesting_data, k.LOCK_DATA AS blocking_data
                 FROM performance_schema.data_lock_waits w
                 JOIN performance_schema.data_locks r ON r.ENGINE=w.ENGINE AND r.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID
                 JOIN performance_schema.data_locks k ON k.ENGINE=w.ENGINE AND k.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID
                 JOIN performance_schema.threads rt ON rt.THREAD_ID=w.REQUESTING_THREAD_ID
                 JOIN performance_schema.threads kt ON kt.THREAD_ID=w.BLOCKING_THREAD_ID
                 WHERE rt.PROCESSLIST_ID=? AND kt.PROCESSLIST_ID=? AND r.OBJECT_SCHEMA=?
                   AND r.OBJECT_NAME=? AND k.OBJECT_SCHEMA=r.OBJECT_SCHEMA AND k.OBJECT_NAME=r.OBJECT_NAME',
                [$b, $a, DB::connection()->getDatabaseName(), 'late_receipt_reviews']
            );
            return ['available' => true, 'rows' => $rows];
        } catch (Throwable $e) {
            return ['available' => false, 'reason' => get_class($e), 'rows' => []];
        }
    }

    private function scenario(string $mode): void
    {
        $control = $this->fixture('CONTROL-'.$mode, $mode);
        $before = $this->snapshot();
        $t = hrtime(true);
        $this->operation($mode, $control);
        $controlMs = (hrtime(true) - $t) / 1e6;
        $this->assertOutcome($mode, $control, $before);
        if ($controlMs >= 5000) {
            self::markTestIncomplete('L01 INCONCLUSIVE: valid control exceeded observation window.');
        }
        $a = $this->account('A-'.$mode);
        $b = $this->fixture('B-'.$mode, $mode);
        self::assertNotSame($a['subscriber'], $b['subscriber']);
        self::assertNotSame($a['meter'], $b['meter']);
        $before = $this->snapshot();
        foreach (['readings', 'invoices', 'payments', 'transactions'] as $table) {
            self::assertFalse(DB::table($table)->where('meter_id', $a['meter'])->exists());
        }
        self::assertFalse(DB::table('late_receipt_reviews')->where('submitted_meter_id', $a['meter'])->exists());
        $ledger = app(TransactionService::class);
        $balance = fn (array $f) => $ledger->accountBalanceMinor(Subscriber::findOrFail($f['subscriber']));
        $balances = [$balance($a), $balance($b)];
        $dir = sys_get_temp_dir().'/water-l01-'.Str::uuid();
        if (! mkdir($dir, 0700)) {
            throw new \RuntimeException('L01 BLOCKED: cannot create coordination directory.');
        }
        $evidence = ['mode' => $mode, 'control_ms' => $controlMs];
        // Ordinary schema-local metadata; never change a plan or index.
        try {
            $evidence['index'] = DB::select('SHOW INDEX FROM late_receipt_reviews');
            $evidence['plan'] = DB::select('EXPLAIN SELECT id FROM late_receipt_reviews WHERE submitted_meter_id=? AND status=? ORDER BY id LIMIT 1 FOR UPDATE', [$a['meter'], 'pending']);
        } catch (Throwable $e) {
            $evidence['plan_unavailable'] = get_class($e);
        }
        DB::disconnect();
        $pids = [];
        $deadline = hrtime(true) + 30000000000;
        $joinError = null;
        try {
            $pids['A'] = $this->fork($dir, 'A', function () use ($dir, $a): void {
                DB::beginTransaction();
                try {
                    Subscriber::query()->lockForUpdate()->findOrFail($a['subscriber']);
                    $meter = Meter::query()->lockForUpdate()->findOrFail($a['meter']);
                    if ((int) $meter->subscriber_id !== $a['subscriber']
                        || app(MeterDataPurger::class)->hasPendingReceiptReviewLocked($a['meter'])) {
                        throw new \RuntimeException('L01 BLOCKED: A guard did not return false.');
                    }
                    self::mark($dir, 'guard-held');
                    self::await($dir, 'release', 20);
                } finally {
                    DB::rollBack();
                    self::mark($dir, 'A-released');
                }
            });
            $pids['B'] = $this->fork($dir, 'B', function () use ($dir, $b, $mode): void {
                self::await($dir, 'guard-held', 10);
                DB::connection()->beforeExecuting(function ($sql) use ($dir): void {
                    file_put_contents($dir.'/B-queries', json_encode(['phase' => 'start', 'ns' => hrtime(true), 'sql' => $sql], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
                });
                DB::listen(function ($event) use ($dir): void {
                    file_put_contents($dir.'/B-queries', json_encode(['phase' => 'end', 'ns' => hrtime(true), 'sql' => $event->sql], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
                });
                self::mark($dir, 'B-start');
                $this->operation($mode, $b);
                if (DB::transactionLevel() !== 0) {
                    throw new \RuntimeException('L01 BLOCKED: B did not exit its transaction.');
                }
                self::mark($dir, 'B-committed');
            });
            // All parent barriers share the hard join budget, leaving time to release/reap.
            $awaitParent = static fn (string $name) => self::await($dir, $name,
                min(10.0, max(0.0, ($deadline - hrtime(true) - 5000000000) / 1e9)));
            $ar = $awaitParent('A-ready');
            $br = $awaitParent('B-ready');
            $awaitParent('guard-held');
            $start = $awaitParent('B-start');
            $until = min($deadline - 5000000000, $start['ns'] + 5000000000);
            while (hrtime(true) < $until && self::read($dir, 'B-result') === null) {
                usleep(10000);
            }
            $evidence['connections'] = [$ar['connection'], $br['connection']];
            $evidence['observation_ms'] = (hrtime(true) - $start['ns']) / 1e6;
            if (self::read($dir, 'B-committed') === null && self::read($dir, 'B-result') === null) {
                $evidence['wait'] = $this->waitEvidence($ar['connection'], $br['connection']);
            }
        } catch (Throwable $e) {
            $evidence['coordination_error'] = $e->getMessage();
        } finally {
            self::mark($dir, 'release');
            // Poll boundedly; never block forever waiting for a forked worker.
            while ($pids !== [] && hrtime(true) < $deadline) {
                foreach ($pids as $name => $pid) {
                    $w = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($w === $pid || $w === -1) {
                        if ($w !== $pid || ! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                            $joinError = 'worker '.$name.' did not exit cleanly';
                        }
                        unset($pids[$name]);
                    }
                }
                usleep(10000);
            }
            if ($pids !== []) {
                $joinError = '30-second worker join exceeded';
                foreach ($pids as $pid) {
                    posix_kill($pid, SIGKILL);
                }
                $killDeadline = hrtime(true) + 2000000000;
                while ($pids !== [] && hrtime(true) < $killDeadline) {
                    foreach ($pids as $name => $pid) {
                        if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                            unset($pids[$name]);
                        }
                    }
                    usleep(10000);
                }
            }
            foreach (['A-ready', 'B-ready', 'guard-held', 'B-start', 'B-committed', 'release', 'A-released', 'A-result', 'B-result'] as $name) {
                $evidence[$name] = self::read($dir, $name);
            }
            $evidence['queries'] = is_file($dir.'/B-queries') ? file($dir.'/B-queries', FILE_IGNORE_NEW_LINES) : [];
            $evidence['join_error'] = $joinError;
            $evidence['unreaped'] = array_values($pids);
            fwrite(STDOUT, 'L01_EVIDENCE '.json_encode($evidence, JSON_THROW_ON_ERROR).PHP_EOL);
            foreach (glob($dir.'/*') as $path) {
                unlink($path);
            }
            rmdir($dir);
            DB::purge();
        }
        $attributed = ! empty($evidence['wait']['rows']);
        // A driver lock timeout alone is not causal evidence. Only an exact-table
        // wait while the sole competing A transaction was held can corroborate it.
        $bError = $evidence['B-result'] ?? [];
        $exactWaitError = (int) ($bError['driver_code'] ?? 0) === 1205
            && str_contains(strtolower((string) ($bError['sql'] ?? '')), 'late_receipt_reviews')
            && isset($evidence['guard-held']['ns'], $bError['ns'], $evidence['release']['ns'])
            && $evidence['guard-held']['ns'] < $bError['ns'] && $bError['ns'] < $evidence['release']['ns'];
        // Preserve this evidence for D, but do not infer blocker identity from an
        // error or timestamps alone when the observer cannot identify it.
        if ($exactWaitError) {
            fwrite(STDOUT, 'L01 exact-table 1205 while A held; blocker attribution requires review.'.PHP_EOL);
        }
        if ($joinError !== null || ! ($evidence['A-result']['ok'] ?? false) || isset($evidence['coordination_error'])) {
            self::markTestIncomplete('L01 INCONCLUSIVE: worker/barrier failure; inspect L01_EVIDENCE.');
        }
        if ($evidence['B-result']['ok'] ?? false) {
            $this->assertOutcome($mode, $b, $before);
            self::assertSame($balances, [$balance($a), $balance($b)]);
        } else {
            self::assertSame($before, $this->snapshot(), 'L01 INVARIANT: failed B operation left changes.');
        }
        if ($attributed) {
            self::fail('L01-'.$mode.' FAIL: observed B waiting on A late_receipt_reviews row/gap lock.');
        }
        if (! ($evidence['B-result']['ok'] ?? false) || $evidence['B-committed'] === null
            || $evidence['B-committed']['ns'] >= $evidence['release']['ns']) {
            self::markTestIncomplete('L01-'.$mode.' INCONCLUSIVE: no commit before release and no attributable wait evidence; do not infer defect from timeout.');
        }
        fwrite(STDOUT, 'L01-'.$mode.' PASS: independent B commit preceded A release.'.PHP_EOL);
        self::assertLessThan($evidence['release']['ns'], $evidence['B-committed']['ns']);
    }
}

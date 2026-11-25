<?php

declare(strict_types=1);

use App\Support\FinancialClosePolicy;

require_once __DIR__.'/../../app/Support/FinancialClosePolicy.php';

// Synthetic counter boundaries only; no framework, database or product calls.
$p = new FinancialClosePolicy();
$now = new DateTimeImmutable('2026-12-01T00:00:00+03:00');
$attempt = '11111111-1111-4111-8111-111111111111';
$approveId = '22222222-2222-4222-8222-222222222222';
$abortId = '33333333-3333-4333-8333-333333333333';
$nextId = '44444444-4444-4444-8444-444444444444';
$priorHash = str_repeat('a', 64);
$manifest = str_repeat('b', 64);
$context = ['scope' => 'water', 'gate_held' => true, 'state_verified' => true, 'actor_active' => true,
    'actor' => 1, 'authorized' => 'request', 'scope_fenced' => false, 'predecessor_verified' => true,
    'predecessor_hash' => $priorHash, 'writers_drained' => true, 'request_unused' => true];
$snapshot = ['manifest_hash' => $manifest, 'coverage' => array_fill_keys(['allocation', 'bank', 'cash', 'custody', 'liability', 'recoverable', 'subscriber', 'transit'], true),
    'rows' => [['sector' => 'subscriber', 'key' => 'S1', 'opening' => -10025, 'movement' => 25, 'closing' => -10000, 'source_hash' => $priorHash]], 'carries' => []];
$checks = $cases = $failed = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    ++$checks;
    if (!$ok) throw new RuntimeException($message);
};
$case = static function (string $name, callable $fn) use (&$cases, &$failed): void {
    ++$cases;
    try { $fn(); echo "PASS $name\n"; }
    catch (Throwable $e) { ++$failed; echo "FAIL $name: ".$e->getMessage()."\n"; }
};
$reject = static function (callable $fn, string $expected = 'counter_overflow') use ($check): void {
    try { $fn(); } catch (DomainException $e) { $check($e->getMessage() === $expected, 'Unexpected rejection '.$e->getMessage()); return; }
    $check(false, 'Expected '.$expected.', but transition succeeded');
};
$openAt = static fn (int $v) => array_replace($p->open('water', '2026-11', 'Asia/Aden'), ['version' => $v]);
$freeze = static fn (array $period, int $epoch, string $uuid = '11111111-1111-4111-8111-111111111111') => $p->freeze($period,
    ['version' => $period['version'], 'uuid' => $uuid, 'reason' => 'Synthetic monthly close'], $context + ['epoch' => $epoch], $now);
$frozenContext = static fn (array $period, string $op, int $actor) => array_replace($context, ['authorized' => $op,
    'actor' => $actor, 'scope_fenced' => true, 'epoch' => $period['attempt']['epoch'], 'coverage_verified' => true,
    'no_unexplained_variance' => true, 'carry_evidence_verified' => true, 'manifest_hash' => $manifest]);
$prepare = static fn (array $period) => $p->prepare($period,
    ['version' => $period['version'], 'attempt_uuid' => $period['attempt']['uuid']], $frozenContext($period, 'prepare', 2), $snapshot, $now);
$approvalCommand = static fn (array $period) => ['version' => $period['version'], 'uuid' => $approveId,
    'attempt_uuid' => $period['attempt']['uuid'], 'snapshot_hash' => $period['attempt']['prepared']['hash']];
$approvalFacts = static fn (array $period) => $frozenContext($period, 'approve', 3) + ['snapshot_verified' => true,
    'snapshot_hash' => $period['attempt']['prepared']['hash']];
$approve = static fn (array $period) => $p->approve($period, $approvalCommand($period), $approvalFacts($period), $now);
$abort = static fn (array $period) => $p->abort($period,
    ['version' => $period['version'], 'uuid' => $abortId, 'attempt_uuid' => $period['attempt']['uuid'], 'reason' => 'Synthetic independent abort'],
    $frozenContext($period, 'abort', 3), $now);
$outcome = static function (callable $fn): string {
    try { return $fn()['state']; } catch (DomainException $e) { return $e->getMessage(); }
};

// Complete boundary grid: freeze must reserve 3 version steps and 2 epoch steps.
foreach ([0, 1, 2, 3, 4] as $vHeadroom) {
    foreach ([0, 1, 2, 3] as $eHeadroom) {
        $case("freeze headroom v=$vHeadroom e=$eHeadroom", function () use ($vHeadroom, $eHeadroom, $openAt, $freeze, $prepare, $approve, $abort, $outcome, $check) {
            $v = PHP_INT_MAX - $vHeadroom;
            $e = PHP_INT_MAX - $eHeadroom;
            $period = $openAt($v);
            $original = $period;
            $safe = $vHeadroom >= 3 && $eHeadroom >= 2;
            try { $frozen = $freeze($period, $e); }
            catch (DomainException $ex) {
                $check(!$safe && $ex->getMessage() === 'counter_overflow', 'Unexpected freeze rejection: '.$ex->getMessage());
                $check($period === $original, 'Rejected freeze changed input');
                return;
            }
            $check($safe, 'Unsafe FROZEN accepted; abort='.$outcome(fn () => $abort($frozen)).'; prepare/approve='.$outcome(fn () => $approve($prepare($frozen))).'; prepare/abort='.$outcome(fn () => $abort($prepare($frozen))));
            $check($frozen['state'] === 'FROZEN' && $frozen['version'] === $v + 1 && $frozen['attempt']['epoch'] === $e + 1, 'Freeze counters');
            $check($period === $original, 'Freeze changed input');
        });
    }
}

foreach ([[3, 2], [3, 3], [4, 2], [4, 3], [100, 100]] as [$vHeadroom, $eHeadroom]) {
    $v = PHP_INT_MAX - $vHeadroom;
    $e = PHP_INT_MAX - $eHeadroom;
    $case("complete close v=$vHeadroom e=$eHeadroom", function () use ($v, $e, $p, $now, $openAt, $freeze, $prepare, $approve, $approvalCommand, $approvalFacts, $check) {
        $frozen = $freeze($openAt($v), $e);
        $prepared = $prepare($frozen);
        $closed = $approve($prepared);
        $check($prepared['version'] === $v + 2 && $prepared['state'] === 'FROZEN' && $prepared['attempt']['epoch'] === $e + 1, 'Prepare counters');
        $check($closed['version'] === $v + 3 && $closed['closure']['release_epoch'] === $e + 2 && $closed['state'] === 'CLOSED', 'Close counters');
        $check($closed['closure']['snapshot']['subscriber_debt'] === '10000' && $closed['attempt'] === null, 'Snapshot retained');
        $replayFacts = array_replace($approvalFacts($prepared), ['scope_fenced' => false, 'epoch' => $e + 2, 'persisted_effects_verified' => true, 'request_unused' => false]);
        $check($p->approve($closed, $approvalCommand($prepared), $replayFacts, $now) === $closed, 'Exact replay at boundary must not increment');
    });
    foreach ([false, true] as $preparedFirst) {
        $case("abort v=$vHeadroom e=$eHeadroom prepared=".(int) $preparedFirst, function () use ($v, $e, $preparedFirst, $openAt, $freeze, $prepare, $abort, $check, $reject, $nextId) {
            $frozen = $freeze($openAt($v), $e);
            if ($preparedFirst) $frozen = $prepare($frozen);
            $result = $abort($frozen);
            $release = $result['aborted'][0]['release_epoch'];
            $check($result['state'] === 'OPEN' && $result['attempt'] === null && $result['version'] === $v + ($preparedFirst ? 3 : 2) && $release === $e + 2, 'Abort counters');
            $check($result['aborted'][0]['attempt'] === $frozen['attempt'] && $result['closure'] === null, 'Abort history');
            if ($result['version'] > PHP_INT_MAX - 3 || $release > PHP_INT_MAX - 2) {
                $reject(fn () => $freeze($result, $release, $nextId));
            } else {
                $next = $freeze($result, $release, $nextId);
                $check($next['attempt']['epoch'] === $release + 1 && $next['aborted'] === $result['aborted'], 'New attempt preserves history');
            }
        });
    }
}

// Defensive prepare guard: synthetic old/hydrated FROZEN state with inadequate headroom.
// Not a persistence claim. A still-abortable state must retain its last version step.
$case('prepare must not spend last version on a recoverable frozen state', function () use ($openAt, $freeze, $prepare, $abort, $reject, $check) {
    $frozen = $freeze($openAt(0), 0);
    $frozen['version'] = PHP_INT_MAX - 1;
    $original = $frozen;
    $reject(fn () => $prepare($frozen));
    $check($frozen === $original, 'Rejected prepare changed input');
    $result = $abort($frozen);
    $check($result['state'] === 'OPEN' && $result['version'] === PHP_INT_MAX, 'Last version reserved for abort');
});
$case('prepare must reject an already exhausted frozen epoch', function () use ($openAt, $freeze, $prepare, $reject, $check) {
    $frozen = $freeze($openAt(0), 0);
    $frozen['attempt']['epoch'] = PHP_INT_MAX;
    $original = $frozen;
    $reject(fn () => $prepare($frozen));
    $check($frozen === $original, 'Rejected prepare changed input');
});
$case('prepare exactly two version steps and one epoch step left', function () use ($openAt, $freeze, $prepare, $approve, $check) {
    $frozen = $freeze($openAt(PHP_INT_MAX - 3), PHP_INT_MAX - 2);
    $closed = $approve($prepare($frozen));
    $check($closed['version'] === PHP_INT_MAX && $closed['closure']['release_epoch'] === PHP_INT_MAX, 'Exact maximum is legal');
});
$case('normal counters remain usable', function () use ($openAt, $freeze, $prepare, $approve, $check) {
    $closed = $approve($prepare($freeze($openAt(0), 10)));
    $check($closed['version'] === 3 && $closed['closure']['release_epoch'] === 12, 'Normal lifecycle');
});
$case('invalid epoch stays invalid', function () use ($openAt, $freeze, $reject) {
    $reject(fn () => $freeze($openAt(0), -1), 'counter');
});

echo ($failed === 0 ? 'PASS' : 'FAIL').": $cases cases / ".($cases - $failed)." passed / $checks checks / $failed failures / 0 skips\n";
exit($failed === 0 ? 0 : 1);

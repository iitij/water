<?php

declare(strict_types=1);

use App\Support\FinancialClosePolicy;

require_once __DIR__.'/../../app/Support/FinancialClosePolicy.php';

// Standalone contract tests: no Laravel bootstrap, DB, vendor or user device.
$p = new FinancialClosePolicy();
$now = new DateTimeImmutable('2026-12-01T00:00:00+03:00');
$before = new DateTimeImmutable('2026-11-30T20:59:59Z');
$attempt = '11111111-1111-4111-8111-111111111111';
$approval = '22222222-2222-4222-8222-222222222222';
$abort = '33333333-3333-4333-8333-333333333333';
$h = str_repeat('a', 64);
$m = str_repeat('b', 64);
$open = $p->open('water', '2026-11', 'Asia/Aden');
$ctx = ['scope' => 'water', 'gate_held' => true, 'state_verified' => true, 'actor_active' => true,
    'actor' => 1, 'authorized' => 'request', 'scope_fenced' => false, 'epoch' => 10,
    'predecessor_verified' => true, 'predecessor_hash' => $h, 'writers_drained' => true, 'request_unused' => true];
$freeze = ['version' => 0, 'uuid' => $attempt, 'reason' => 'Monthly close request'];
$prepCtx = array_replace($ctx, ['actor' => 2, 'authorized' => 'prepare', 'scope_fenced' => true, 'epoch' => 11,
    'coverage_verified' => true, 'no_unexplained_variance' => true, 'carry_evidence_verified' => true, 'manifest_hash' => $m]);
$prep = ['version' => 1, 'attempt_uuid' => $attempt];
$row = static fn ($sector, $key, $o, $v, $c) => ['sector' => $sector, 'key' => $key, 'opening' => $o, 'movement' => $v, 'closing' => $c, 'source_hash' => $h];
$input = ['manifest_hash' => $m, 'coverage' => array_fill_keys(['subscriber', 'allocation', 'cash', 'bank', 'transit', 'custody', 'liability', 'recoverable'], true),
    'rows' => [$row('subscriber', 'S1', -10025, 0, -10025), $row('subscriber', 'S2', 10025, 0, 10025),
        $row('bank', 'B1', 600000000000, 600000000000, 1200000000000), $row('transit', 'T1', 0, 10025, 10025),
        $row('allocation', 'A1', 0, 10025, 10025) + ['allocated' => 10000, 'surplus' => 25]],
    'carries' => [['id' => 'C1', 'sector' => 'transit', 'row_key' => 'T1', 'amount' => 10025, 'owner' => 5, 'due_on' => '2026-12-10', 'evidence_hash' => $h]]];
$checks = $cases = 0;
$check = static function (bool $ok, string $name) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($name); };
$reject = static function (string $name, string $code, callable $fn) use ($check, &$cases): void {
    ++$cases;
    try { $fn(); } catch (DomainException $e) { $check($e->getMessage() === $code, $name.': '.$e->getMessage()); return; }
    throw new RuntimeException('Accepted invalid case: '.$name);
};
$pass = static function (string $name, callable $fn) use (&$cases): void { ++$cases; $fn(); echo "PASS $name\n"; };
$pass('calendar and immutable input', function () use ($p, $open, $check, $ctx, $freeze, $now) {
    $check($open['state'] === 'OPEN' && $open['next'] === '2026-12-01', 'month boundary');
    $check($p->open('water', '2028-02', 'Asia/Aden')['next'] === '2028-03-01', 'leap month');
    $f = $p->freeze($open, $freeze, $ctx, $now);
    $check($f['state'] === 'FROZEN' && $f['attempt']['epoch'] === 11 && $f['version'] === 1, 'freeze');
    $check($open['state'] === 'OPEN' && $open['attempt'] === null, 'no mutation');
    $check($p->freeze($open, $freeze, $ctx, $now->setTimezone(new DateTimeZone('UTC'))) === $f, 'same instant');
});
$reject('invalid month', 'month', fn () => $p->open('water', '2026-13', 'Asia/Aden'));
$reject('year overflow', 'month', fn () => $p->open('water', '9999-12', 'Asia/Aden'));
$reject('offset zone', 'timezone', fn () => $p->open('water', '2026-11', '+03:00'));
$reject('early freeze', 'period_not_ended', fn () => $p->freeze($open, $freeze, $ctx, $before));
foreach (['gate_held', 'state_verified', 'actor_active'] as $k) $reject('context '.$k, 'untrusted_context', fn () => $p->freeze($open, $freeze, array_replace($ctx, [$k => false]), $now));
foreach (['predecessor_verified', 'writers_drained'] as $k) $reject('freeze '.$k, 'predecessor_or_drain', fn () => $p->freeze($open, $freeze, array_replace($ctx, [$k => false]), $now));
$reject('wrong scope', 'untrusted_context', fn () => $p->freeze($open, $freeze, array_replace($ctx, ['scope' => 'other']), $now));
$reject('wrong role', 'untrusted_context', fn () => $p->freeze($open, $freeze, array_replace($ctx, ['authorized' => 'post']), $now));
$reject('stale version', 'stale_version', fn () => $p->freeze($open, array_replace($freeze, ['version' => 1]), $ctx, $now));
$reject('duplicate request', 'request_used', fn () => $p->freeze($open, $freeze, array_replace($ctx, ['request_unused' => false]), $now));
$reject('epoch overflow', 'counter_overflow', fn () => $p->freeze($open, $freeze, array_replace($ctx, ['epoch' => PHP_INT_MAX]), $now));
$frozen = $p->freeze($open, $freeze, $ctx, $now);
$post = array_replace($ctx, ['authorized' => 'post']);
$pass('valid posting guard', function () use ($p, $open, $post, $now, $check) { $p->assertCanPost($open, '2026-11-30', $post, $now); $check(true, 'post allowed'); });
$reject('missing period', 'missing_period', fn () => $p->assertCanPost(null, '2026-11-30', $post, $now));
$reject('frozen period', 'period_not_open', fn () => $p->assertCanPost($frozen, '2026-11-30', $post, $now));
$reject('scope frozen other open month', 'period_not_open', fn () => $p->assertCanPost($p->open('water', '2026-12', 'Asia/Aden'), '2026-12-01', array_replace($post, ['scope_fenced' => true]), $now));
$reject('outside period', 'effective_date', fn () => $p->assertCanPost($open, '2026-12-01', $post, $now));
$reject('future effective date', 'effective_date', fn () => $p->assertCanPost($open, '2026-11-30', $post, new DateTimeImmutable('2026-11-29T12:00:00+03:00')));
$reject('invalid effective date', 'date', fn () => $p->assertCanPost($open, '2026-11-31', $post, $now));
$reject('prepare before freeze', 'stale_version', fn () => $p->prepare($open, $prep, $prepCtx, $input, $now));
$reject('stale epoch', 'stale_attempt', fn () => $p->prepare($frozen, $prep, array_replace($prepCtx, ['epoch' => 10]), $input, $now));
$reject('wrong attempt', 'stale_attempt', fn () => $p->prepare($frozen, array_replace($prep, ['attempt_uuid' => $approval]), $prepCtx, $input, $now));
$reject('prepare time travel', 'event_time', fn () => $p->prepare($frozen, $prep, $prepCtx, $input, $before));
foreach (['coverage_verified', 'no_unexplained_variance', 'carry_evidence_verified'] as $k) $reject($k, 'incomplete_coverage', fn () => $p->prepare($frozen, $prep, array_replace($prepCtx, [$k => false]), $input, $now));
$reject('manifest mismatch', 'manifest_changed', fn () => $p->prepare($frozen, $prep, array_replace($prepCtx, ['manifest_hash' => $h]), $input, $now));
$bad = $input; unset($bad['coverage']['cash']);
$reject('missing sector', 'coverage', fn () => $p->prepare($frozen, $prep, $prepCtx, $bad, $now));
$bad = $input; $bad['coverage']['cash'] = 1;
$reject('coerced coverage', 'coverage', fn () => $p->prepare($frozen, $prep, $prepCtx, $bad, $now));
foreach ([['closing', -10024, 'conservation'], ['movement', 0.0, 'minor_integer'], ['opening', '-10025', 'minor_integer'], ['opening', PHP_INT_MIN, 'minor_integer']] as [$key, $value, $code]) {
    $bad = $input; $bad['rows'][0][$key] = $value;
    $reject('bad row '.$key.' '.$code, $code, fn () => $p->prepare($frozen, $prep, $prepCtx, $bad, $now));
}
$bad = $input; $bad['rows'][] = $bad['rows'][0];
$reject('duplicate row', 'duplicate_row', fn () => $p->prepare($frozen, $prep, $prepCtx, $bad, $now));
$bad = $input; $bad['rows'][4]['surplus'] = 24;
$reject('allocation conservation', 'allocation_conservation', fn () => $p->prepare($frozen, $prep, $prepCtx, $bad, $now));
$bad = $input; $bad['rows'][] = $row('cash', 'X', 0, -1, -1);
$reject('negative custody cash', 'negative_balance', fn () => $p->prepare($frozen, $prep, $prepCtx, $bad, $now));
$bad = $input; $bad['rows'][] = $row('bank', 'Z', PHP_INT_MAX, 0, PHP_INT_MAX);
$reject('aggregate overflow', 'aggregate_overflow', fn () => $p->prepare($frozen, $prep, $prepCtx, $bad, $now));
$bad = $input; $bad['rows'][] = $row('bank', 'Z', PHP_INT_MAX, 1, 0);
$reject('row overflow', 'aggregate_overflow', fn () => $p->prepare($frozen, $prep, $prepCtx, $bad, $now));
$bad = $input; $bad['rows'][] = $row('bank', 'Z', -PHP_INT_MAX, -1, 0);
$reject('negative overflow', 'aggregate_overflow', fn () => $p->prepare($frozen, $prep, $prepCtx, $bad, $now));
foreach ([['amount', 10024, 'carry_not_conserved'], ['amount', 10026, 'carry_not_conserved'], ['amount', 0, 'carry_identity_or_amount'], ['row_key', 'missing', 'carry_identity_or_amount'], ['due_on', '2026-11-30', 'carry_overdue'], ['sector', 'bank', 'carry_sector'], ['owner', 0, 'positive_integer'], ['evidence_hash', 'bad', 'digest']] as [$key, $value, $code]) {
    $bad = $input; $bad['carries'][0][$key] = $value;
    $reject('bad carry '.$key.' '.$code, $code, fn () => $p->prepare($frozen, $prep, $prepCtx, $bad, $now));
}
$bad = $input; $bad['carries'][] = $bad['carries'][0];
$reject('duplicate carry', 'carry_identity_or_amount', fn () => $p->prepare($frozen, $prep, $prepCtx, $bad, $now));
$bad = $input; $bad['carries'] = [];
$reject('missing carry', 'carry_not_conserved', fn () => $p->prepare($frozen, $prep, $prepCtx, $bad, $now));
$prepared = $p->prepare($frozen, $prep, $prepCtx, $input, $now);
$hash = $prepared['attempt']['prepared']['hash'];
$pass('canonical snapshot and independent debt credit', function () use ($p, $prepared, $frozen, $prep, $prepCtx, $input, $now, $check, $hash) {
    $s = $prepared['attempt']['prepared']['snapshot'];
    $check($s['totals']['bank']['closing'] === '1200000000000', 'wide aggregate');
    $check($s['subscriber_debt'] === '10025' && $s['subscriber_credit'] === '10025', 'do not net debt and credit');
    $check($frozen['attempt']['prepared'] === null && $prepared['version'] === 2, 'immutable input');
    $reordered = $input; $reordered['rows'] = array_reverse($input['rows']); $reordered['coverage'] = array_reverse($input['coverage'], true);
    $check($p->prepare($frozen, $prep, $prepCtx, $reordered, $now)['attempt']['prepared']['hash'] === $hash, 'canonical ordering');
});
$approve = ['version' => 2, 'uuid' => $approval, 'attempt_uuid' => $attempt, 'snapshot_hash' => $hash];
$appCtx = array_replace($prepCtx, ['actor' => 3, 'authorized' => 'approve', 'snapshot_verified' => true, 'snapshot_hash' => $hash]);
foreach ([1, 2] as $actor) $reject('self approval '.$actor, 'self_approval', fn () => $p->approve($prepared, $approve, array_replace($appCtx, ['actor' => $actor]), $now));
$reject('unprepared approval', 'not_prepared', fn () => $p->approve($frozen, array_replace($approve, ['version' => 1]), $appCtx, $now));
$reject('stale predecessor', 'predecessor_changed', fn () => $p->approve($prepared, $approve, array_replace($appCtx, ['predecessor_hash' => $m]), $now));
foreach (['snapshot_verified' => false, 'snapshot_hash' => $h, 'manifest_hash' => $h] as $k => $v) $reject('approval '.$k, 'snapshot_changed', fn () => $p->approve($prepared, $approve, array_replace($appCtx, [$k => $v]), $now));
$reject('same request key', 'request_used', fn () => $p->approve($prepared, array_replace($approve, ['uuid' => $attempt]), $appCtx, $now));
$tampered = $prepared; $tampered['attempt']['prepared']['snapshot']['rows'][0]['closing'] = '999';
$reject('tampered prepared snapshot', 'snapshot_changed', fn () => $p->approve($tampered, $approve, $appCtx, $now));
$closed = $p->approve($prepared, $approve, $appCtx, $now);
$replayCtx = array_replace($appCtx, ['scope_fenced' => false, 'epoch' => 12, 'request_unused' => false, 'persisted_effects_verified' => true]);
$pass('close and exact replay', function () use ($p, $closed, $prepared, $approve, $replayCtx, $now, $check) {
    $check($closed['state'] === 'CLOSED' && $closed['attempt'] === null && $closed['version'] === 3, 'closed');
    $check($closed['closure']['release_epoch'] === 12 && $prepared['state'] === 'FROZEN', 'epoch and immutable state');
    $check($p->approve($closed, $approve, $replayCtx, $now->modify('+1 day')) === $closed, 'no new close');
});
foreach (['uuid' => $abort, 'snapshot_hash' => $h, 'version' => 3, 'attempt_uuid' => $abort] as $k => $v) $reject('replay changed '.$k, 'close_replay_conflict', fn () => $p->approve($closed, array_replace($approve, [$k => $v]), $replayCtx, $now));
$bad = $closed; $bad['month'] = '2026-10'; $bad['start'] = '2026-10-01'; $bad['next'] = '2026-11-01';
$reject('replay transplanted closure', 'close_replay_conflict', fn () => $p->approve($bad, $approve, $replayCtx, $now));
$reject('replay unverified effects', 'close_replay_conflict', fn () => $p->approve($closed, $approve, array_replace($replayCtx, ['persisted_effects_verified' => false]), $now));
$bad = $closed; $bad['closure']['reason'] = 'changed';
$reject('replay tampered closure', 'close_replay_conflict', fn () => $p->approve($bad, $approve, $replayCtx, $now));
$bad = $open; $bad['attempt'] = 'malformed';
$reject('invalid open state', 'invalid_state', fn () => $p->assertCanPost($bad, '2026-11-30', $post, $now));
$reject('closed posting', 'period_not_open', fn () => $p->assertCanPost($closed, '2026-11-30', $post, $now));
$reject('closed freeze', 'period_not_open', fn () => $p->freeze($closed, array_replace($freeze, ['version' => 3]), $ctx, $now));
$abortCtx = array_replace($prepCtx, ['actor' => 3, 'authorized' => 'abort']);
$abortCmd = ['version' => 2, 'uuid' => $abort, 'attempt_uuid' => $attempt, 'reason' => 'Independent abort reason'];
$reject('closed abort', 'not_frozen', fn () => $p->abort($closed, array_replace($abortCmd, ['version' => 3]), $abortCtx, $now));
$reject('self abort', 'self_approval', fn () => $p->abort($prepared, $abortCmd, array_replace($abortCtx, ['actor' => 2]), $now));
$pass('audited abort before approval', function () use ($p, $prepared, $abortCmd, $abortCtx, $now, $check, $ctx, $freeze, $attempt) {
    $a = $p->abort($prepared, $abortCmd, $abortCtx, $now);
    $check($a['state'] === 'OPEN' && $a['attempt'] === null && $a['closure'] === null && $a['version'] === 3, 'aborted open');
    $check($a['aborted'][0]['attempt']['uuid'] === $attempt && $a['aborted'][0]['release_epoch'] === 12, 'retained history');
    $f = $p->freeze($a, array_replace($freeze, ['version' => 3, 'uuid' => '44444444-4444-4444-8444-444444444444']), array_replace($ctx, ['epoch' => 12]), $now);
    $check($f['attempt']['epoch'] === 13 && count($f['aborted']) === 1, 'new attempt, old history kept');
});
foreach (['meter_cache' => 9999999999, 'tariff_rate' => 9999999999, 'document' => 999999999999, 'subscriber_cache' => 999999999999, 'cash_session' => 99999999999999, 'treasury_posting' => PHP_INT_MAX] as $field => $bound) {
    $pass('storage '.$field, function () use ($p, $field, $bound, $check) { $p->assertStorageMinor($field, $bound); $check(true, 'bound accepted'); });
    if ($bound < PHP_INT_MAX) $reject('storage overflow '.$field, 'storage_bound', fn () => $p->assertStorageMinor($field, $bound + 1));
}
$reject('negative tariff', 'storage_bound', fn () => $p->assertStorageMinor('tariff_rate', -1));
$reject('float storage', 'minor_integer', fn () => $p->assertStorageMinor('document', 1.0));
$reject('min int storage', 'minor_integer', fn () => $p->assertStorageMinor('treasury_posting', PHP_INT_MIN));
$reject('unknown field', 'unknown_field', fn () => $p->assertStorageMinor('unknown', 1));
echo "PASS: $cases cases / $checks checks / 0 failures / 0 skips\n";

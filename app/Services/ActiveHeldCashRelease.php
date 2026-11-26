<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Subscriber;
use App\Support\SignedMinor;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Explicit prospective activation of a new independent release, never adoption
 * of an existing shadow decision. First slice: one release anchor per credit.
 */
final class ActiveHeldCashRelease
{
    public function protectionActive(): bool
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) return false;
        $sqlite = $driver === 'sqlite';
        $body = $sqlite ? "BEGIN SELECT RAISE(ABORT, 'Active held release is immutable'); END"
            : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active held release is immutable'";
        $normalize = static fn (string $sql): string => preg_replace('/\s+/', ' ', rtrim(trim($sql), ';'));
        $table = 'held_cash_active_releases';
        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'active_held_release_reject_'.strtolower($event);
            if ($sqlite) {
                $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->where('tbl_name', $table)->first();
                if ($row === null || $normalize($row->sql) !== $normalize("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} {$body}")) return false;
            } else {
                $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->where('EVENT_OBJECT_TABLE', $table)
                    ->where('EVENT_MANIPULATION', $event)->where('ACTION_TIMING', 'BEFORE')->first();
                if ($row === null || $normalize($row->ACTION_STATEMENT) !== $normalize($body)) return false;
            }
        }
        if ($sqlite) {
            $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'active_held_release_reject_replace')
                ->where('tbl_name', $table)->first();
            $expected = "CREATE TRIGGER active_held_release_reject_replace BEFORE INSERT ON held_cash_active_releases
                FOR EACH ROW WHEN EXISTS (SELECT 1 FROM held_cash_active_releases a
                WHERE a.journal_id = NEW.journal_id OR a.credit_id = NEW.credit_id) {$body}";
            if ($row === null || $normalize($row->sql) !== $normalize($expected)) return false;
        }
        return true;
    }

    public function hasAccount(int $accountId): bool
    {
        if (DB::table('held_cash_active_releases')->where('treasury_account_id', $accountId)->exists()) return true;
        return AuditEvent::query()->where('event', 'treasury.held_release_activated')
            ->where('entity_type', 'held_cash_active_release')->lockForUpdate()->get()
            ->contains(fn ($audit) => (int) ($audit->after['treasury_account_id'] ?? 0) === $accountId);
    }

    public function amount(int $accountId): int
    {
        if (! $this->hasAccount($accountId)) return 0;
        $proof = app(HeldCashReleaseReadiness::class)->lockedPreview($accountId);
        $state = app(HeldCashReleaseProposalService::class)->verifiedActiveState($proof);
        return SignedMinor::sum($state);
    }

    /** Reconstruct current liquidity, not a historical replay response or a spending approval. */
    public function currentLiquidity(int $accountId): array
    {
        $proof = app(HeldCashReleaseReadiness::class)->lockedPreview($accountId);
        $released = SignedMinor::sum(app(HeldCashReleaseProposalService::class)->verifiedActiveState($proof));
        $protected = SignedMinor::subtract($proof['protected_minor'], $released);
        $margin = SignedMinor::add($proof['margin_minor'], $released);
        if ($protected < 0) throw new DomainException('active_held_release_evidence');
        return [
            'effective_on' => $proof['effective_on'],
            'recorded_balance_minor' => $proof['recorded_balance_minor'],
            'pending_refunds_minor' => $proof['pending_refunds_minor'],
            'conservative_protected_minor' => $proof['protected_minor'],
            'active_released_minor' => $released,
            'protected_minor' => $protected,
            'margin_minor' => $margin,
            'shortfall_minor' => max(0, SignedMinor::negate($margin)),
            'additional_outflow_blocked' => $margin <= 0,
            'closure_authorized' => false,
        ];
    }

    public function afterAllocation(Subscriber $subscriber, int $revisionId, bool $created): void
    {
        if (! $created) return;
        $markers = DB::table('held_cash_active_releases as a')
            ->join('treasury_held_cash_credits as c', 'c.id', '=', 'a.credit_id')
            ->where('c.subscriber_id', $subscriber->id)->orderBy('a.treasury_account_id')
            ->select('a.*')->lockForUpdate()->get();
        if ($markers->isEmpty()) return;
        // Never acquire scope locks after issuance. Ungated allocation-changing
        // paths cannot touch actively released credit.
        FinancialWriteGate::currentContext();
        foreach ($markers->pluck('treasury_account_id')->unique() as $accountId) {
            $proof = app(HeldCashReleaseReadiness::class)->lockedPreview((int) $accountId);
            $active = app(HeldCashReleaseProposalService::class)->verifiedActiveState($proof);
            $restored = DB::table('held_cash_shadow_reinstatements as s')
                ->join('held_cash_active_releases as a', 'a.journal_id', '=', 's.journal_id')
                ->where('a.treasury_account_id', $accountId)->where('s.revision_id', $revisionId)->exists();
            if ($restored && SignedMinor::add($proof['margin_minor'], SignedMinor::sum($active)) < 0) {
                // Candidate optional mutation must roll back in its entirety.
                // Externally completed events need a separate deficit workflow.
                throw new DomainException('active_held_release_reinstatement_liquidity');
            }
        }
    }

    public function verifyMarker(object $marker, object $journal): void
    {
        $audits = AuditEvent::query()->where('event', 'treasury.held_release_activated')
            ->where('entity_type', 'held_cash_active_release')->where('entity_id', $journal->operation_uuid)
            ->lockForUpdate()->get();
        if ($audits->count() !== 1 || (int) $audits->first()->actor_user_id !== (int) $journal->actor_id
            || (int) $marker->credit_id !== (int) $journal->credit_id
            || (int) $marker->treasury_account_id !== (int) $journal->treasury_account_id
            || $journal->kind !== 'release') throw new DomainException('active_held_release_evidence');
        $expected = ['journal_id' => (int) $journal->id, 'credit_id' => (int) $journal->credit_id,
            'treasury_account_id' => (int) $journal->treasury_account_id];
        $actual = $audits->first()->after;
        if (! is_array($actual) || count($actual) !== count($expected)) throw new DomainException('active_held_release_evidence');
        foreach ($expected as $key => $value) {
            if (($actual[$key] ?? null) !== $value) throw new DomainException('active_held_release_evidence');
        }
    }
}

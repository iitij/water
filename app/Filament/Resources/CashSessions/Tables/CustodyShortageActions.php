<?php

declare(strict_types=1);

namespace App\Filament\Resources\CashSessions\Tables;

use App\Models\AuditEvent;
use App\Models\CashSession;
use App\Models\User;
use App\Services\CustodyShortageLossDecision;
use App\Services\CustodyShortageRecoveryProposal;
use App\Services\CustodyShortageRecoveryWriter;
use App\Support\CashCustodyAmounts;
use App\Support\SignedMinor;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** A review interface, never a second implementation of financial settlement. */
final class CustodyShortageActions
{
    public static function make(): array
    {
        return [
            Action::make('propose_shortage_recovery')
                ->label('اقتراح استرداد العجز')
                ->visible(fn (CashSession $record): bool => self::canPropose($record))
                ->form([
                    Hidden::make('operation_uuid')->default(fn () => (string) Str::uuid())->required()->rules(['uuid']),
                    Select::make('account_id')->label('الخزينة المستلمة')->required()->searchable()
                        ->options(fn (): array => DB::table('treasury_accounts')->where('channel', 'cash')
                            ->orderBy('code')->get()->mapWithKeys(fn ($row) => [$row->id => $row->code.' — '.$row->name])->all()),
                    TextInput::make('amount')->label('المبلغ المسترد فعلياً')->required()
                        ->rules(['string', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/']),
                    TextInput::make('reference')->label('مرجع الاسترداد')->required()->minLength(3)->maxLength(150),
                    Textarea::make('evidence')->label('دليل الاسترداد الفعلي')->required()->minLength(10)->maxLength(2000),
                ])
                ->requiresConfirmation()
                ->action(function (CashSession $record, array $data): void {
                    $actor = self::actor($record);
                    $minor = CashCustodyAmounts::input($data['amount'], 'amount', minimum: 1, digitsOnly: true);
                    self::run(fn () => app(CustodyShortageRecoveryProposal::class)->propose($actor, (int) $record->id,
                        (int) $data['account_id'], $data['reference'], $data['evidence'], strtolower($data['operation_uuid']),
                        now()->toDateTimeImmutable(), $minor), 'تم حفظ طلب الاسترداد دون ترحيل نقدي');
                }),
            Action::make('approve_shortage_recovery')
                ->label('اعتماد استرداد العجز')
                ->visible(fn (CashSession $record): bool => self::canWork($record) && self::recoveryOptions($record) !== [])
                ->form([
                    Select::make('operation_uuid')->label('طلب الاسترداد المحفوظ — راجع المبلغ والمرجع والدليل')
                        ->options(fn (CashSession $record): array => self::recoveryOptions($record))->required()->rules(['uuid']),
                ])
                ->modalDescription('اعتماد مستقل لمبلغ تم استلامه فعلياً. لا يمكن تعديل المبلغ أو الخزينة أو مرجع الطلب.')
                ->requiresConfirmation()
                ->action(function (CashSession $record, array $data): void {
                    $actor = self::actor($record);
                    self::run(fn () => app(CustodyShortageRecoveryWriter::class)->approve($actor, (int) $record->id,
                        now()->toDateTimeImmutable(), strtolower($data['operation_uuid'])), 'تم اعتماد الاسترداد وترحيله');
                }),
            Action::make('propose_shortage_loss')
                ->label('اقتراح إثبات خسارة العجز')
                ->visible(fn (CashSession $record): bool => self::canPropose($record))
                ->form([
                    Textarea::make('reason')->label('سبب إثبات الخسارة')->required()->minLength(10)->maxLength(1000),
                    Textarea::make('evidence')->label('دليل الخسارة المتبقية')->required()->minLength(10)->maxLength(2000),
                ])
                ->requiresConfirmation()
                ->action(function (CashSession $record, array $data): void {
                    $actor = self::actor($record);
                    self::run(fn () => app(CustodyShortageLossDecision::class)->propose($actor, (int) $record->id,
                        $data['reason'], $data['evidence'], now()->toDateTimeImmutable()), 'تم حفظ اقتراح الخسارة للمراجعة');
                }),
            Action::make('approve_shortage_loss')
                ->label('اعتماد خسارة العجز')
                ->visible(fn (CashSession $record): bool => self::canWork($record) && self::reviewableLoss($record) !== null)
                ->modalDescription(fn (CashSession $record): string => self::lossDescription($record))
                ->requiresConfirmation()
                ->action(function (CashSession $record): void {
                    $actor = self::actor($record);
                    self::run(fn () => app(CustodyShortageLossDecision::class)->approve($actor, (int) $record->id,
                        now()->toDateTimeImmutable()), 'تم إثبات الخسارة دون حركة نقدية');
                }),
        ];
    }

    private static function canWork(CashSession $record): bool
    {
        if (! app()->environment(['testing', 'staging'])) return false;
        $actor = Auth::guard('web')->user();
        return $actor instanceof User && ! $actor->isCurrentlyDisabled() && $actor->hasRole('super_admin')
            && (int) $record->user_id !== (int) $actor->id && $record->isApproved()
            && $record->settlement_basis === 'physical_cash'
            && $record->variance_amount !== null && str_starts_with((string) $record->variance_amount, '-');
    }

    private static function actor(CashSession $record): User
    {
        abort_unless(self::canWork($record), 403);
        return Auth::guard('web')->user();
    }

    private static function pendingRecoveries(CashSession $record): \Illuminate\Database\Eloquent\Builder
    {
        return CustodyShortageRecoveryProposal::forSession((int) $record->id)
            ->whereNotIn('id', DB::table('custody_shortage_recoveries')->select('proposal_id'));
    }

    private static function loss(CashSession $record, string $event): ?AuditEvent
    {
        return AuditEvent::query()->where('event', $event)->where('entity_type', 'custody_shortage_loss')
            ->where('entity_id', (string) $record->id)->first();
    }

    private static function canPropose(CashSession $record): bool
    {
        if (! self::canWork($record) || self::pendingRecoveries($record)->exists()
            || self::loss($record, CustodyShortageLossDecision::PROPOSED)) return false;
        $original = CashCustodyAmounts::stored($record->getRawOriginal('variance_amount'),
            $record->getConnection()->getDriverName(), signed: true);
        $recovered = SignedMinor::parseUnsigned(DB::table('custody_shortage_recoveries')
            ->where('cash_session_id', $record->id)->sum('amount_minor'));
        // Display eligibility is advisory only; the gated service reconstructs evidence.
        return SignedMinor::negate($original) > $recovered;
    }

    private static function recoveryOptions(CashSession $record): array
    {
        if (! self::canWork($record)) return [];
        $options = [];
        foreach (self::pendingRecoveries($record)->where('actor_user_id', '!=', Auth::guard('web')->id())->get() as $proposal) {
            $p = $proposal->after;
            if (! is_array($p) || ! is_string($p['operation_uuid'] ?? null)
                || ! is_int($p['recovery_minor'] ?? $p['shortage_minor'] ?? null)) continue;
            $options[$p['operation_uuid']] = '#'.$proposal->id.' | '.SignedMinor::decimal($p['recovery_minor'] ?? $p['shortage_minor'])
                .' | خزينة #'.($p['account_id'] ?? '—').' | '.($p['reference'] ?? '—').' | '.($p['evidence'] ?? '—');
        }
        return $options;
    }

    private static function reviewableLoss(CashSession $record): ?AuditEvent
    {
        if (! self::canWork($record) || self::loss($record, CustodyShortageLossDecision::APPROVED)) return null;
        $proposal = self::loss($record, CustodyShortageLossDecision::PROPOSED);
        return $proposal && (int) $proposal->actor_user_id !== (int) Auth::guard('web')->id() ? $proposal : null;
    }

    private static function lossDescription(CashSession $record): string
    {
        $proposal = self::reviewableLoss($record);
        if (! $proposal) return 'لا يوجد طلب صالح للمراجعة.';
        $p = $proposal->after;
        if (! is_array($p) || ! is_int($p['shortage_minor'] ?? null)
            || ! is_int($p['prior_recovery']['recovered_minor'] ?? 0)) return 'بيانات الطلب غير صالحة.';
        $remaining = SignedMinor::subtract($p['shortage_minor'], $p['prior_recovery']['recovered_minor'] ?? 0);
        return '#'.$proposal->id.' | مبلغ الخسارة المقترح: '.SignedMinor::decimal($remaining)
            .' | السبب: '.($p['reason'] ?? '—').' | الدليل: '.($p['evidence'] ?? '—');
    }

    private static function run(callable $operation, string $title): void
    {
        try {
            $operation();
        } catch (DomainException $error) {
            throw ValidationException::withMessages(['data' => $error->getMessage()]);
        }
        Notification::make()->title($title)->success()->send();
    }
}

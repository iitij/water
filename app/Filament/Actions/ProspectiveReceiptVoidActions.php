<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\AuditEvent;
use App\Models\Payment;
use App\Models\User;
use App\Services\GatedReceiptVoidWriter;
use App\Services\PaymentService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Staging/testing only. Preserve the request identity captured when the
 * reviewer opened the modal; never resolve a new request on submission.
 */
final class ProspectiveReceiptVoidActions
{
    public static function make(): array
    {
        return [
            Action::make('prospective_void_request')
                ->label('إلغاء سند القبض')
                ->color('danger')
                ->visible(fn (Payment $record): bool => self::enabled() && self::canRequest(auth()->user())
                    && $record->payment_method === 'cash' && ! $record->isVoided() && ! $record->hasPendingVoidRequest())
                ->fillForm(fn (Payment $record): array => [
                    'operation_uuid' => app(PaymentService::class)->requiresVoidApproval($record) ? null : (string) Str::uuid(),
                ])
                ->form([
                    Hidden::make('operation_uuid')->rules(['nullable', 'uuid']),
                    Textarea::make('reason')->label('سبب الإلغاء')->required()->minLength(10)->maxLength(1000),
                ])
                ->action(function (Payment $record, array $data): void {
                    self::assertEnabled();
                    $actor = auth()->user();
                    abort_unless(self::canRequest($actor), 403);
                    $result = self::execute(fn () => app(GatedReceiptVoidWriter::class)->request(
                        $actor, (int) $record->id, (string) $data['reason'], now()->toDateTimeImmutable(),
                        $data['operation_uuid'] ?? null));
                    Notification::make()->title($result['pending'] ? 'أُرسل طلب الإلغاء للاعتماد' : 'تم إلغاء السند')
                        ->success()->send();
                }),
            self::decision(true),
            self::decision(false),
        ];
    }

    private static function decision(bool $approve): Action
    {
        return Action::make($approve ? 'prospective_void_approve' : 'prospective_void_reject')
            ->label($approve ? 'اعتماد الإلغاء' : 'رفض الإلغاء')
            ->color($approve ? 'danger' : 'gray')
            ->visible(fn (Payment $record): bool => self::enabled() && self::canDecide(auth()->user())
                && $record->payment_method === 'cash' && $record->hasPendingVoidRequest()
                && (int) $record->void_requested_by !== (int) auth()->id()
                && self::requestId($record) !== null)
            ->fillForm(fn (Payment $record): array => [
                'request_id' => self::requestId($record),
                'operation_uuid' => $approve ? (string) Str::uuid() : null,
            ])
            ->form([
                Hidden::make('request_id')->required()->rules(['integer', 'min:1']),
                Hidden::make('operation_uuid')->required($approve)->rules(['nullable', 'uuid']),
                Textarea::make('notes')->label('ملاحظات القرار')->maxLength(1000),
            ])
            ->action(function (Payment $record, array $data) use ($approve): void {
                self::assertEnabled();
                $actor = auth()->user();
                abort_unless(self::canDecide($actor), 403);
                self::execute(fn () => app(GatedReceiptVoidWriter::class)->decide(
                    $actor, (int) $record->id, $approve, $data['notes'] ?? null,
                    now()->toDateTimeImmutable(), $data['operation_uuid'] ?? null, (int) $data['request_id']));
                Notification::make()->title($approve ? 'تم اعتماد الإلغاء' : 'تم رفض طلب الإلغاء')->success()->send();
            });
    }

    private static function requestId(Payment $record): ?int
    {
        $id = AuditEvent::query()->where('entity_type', Payment::class)->where('entity_id', (string) $record->id)
            ->where('event', 'financial.void_request_gated')->orderByDesc('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    private static function enabled(): bool
    {
        return (config('financial_candidate.void_api') === true
            || DB::table('financial_scopes')->where('scope_key', 'water')->exists())
            && app()->environment(['testing', 'staging']);
    }

    private static function assertEnabled(): void
    {
        abort_unless(self::enabled(), 503);
    }

    private static function canRequest(?User $actor): bool
    {
        return $actor !== null && ($actor->hasAnyRole(['super_admin', 'admin'])
            || $actor->can('Void:Payment') || $actor->can('void_payment'));
    }

    private static function canDecide(?User $actor): bool
    {
        return $actor !== null && ($actor->hasAnyRole(['super_admin', 'admin'])
            || $actor->can('payments.void.approve') || $actor->can('Approve:Payment') || $actor->can('approve_payment'));
    }

    private static function execute(callable $action): array
    {
        try {
            return $action();
        } catch (DomainException $error) {
            Notification::make()->title('تعذر تنفيذ العملية المالية')->body($error->getMessage())->danger()->send();
            // Keep modal mounted and never fall back to an ungated write.
            throw ValidationException::withMessages(['data' => $error->getMessage()]);
        }
    }
}

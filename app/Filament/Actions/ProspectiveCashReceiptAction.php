<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\CashSession;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Services\PaymentService;
use App\Services\ProspectiveCashReceiptEntry;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Shared collection modal contract, matching the standard receipt page. */
final class ProspectiveCashReceiptAction
{
    public static function fields(): array
    {
        return [
            Hidden::make('sync_uuid')
                ->default(fn () => (string) Str::uuid())
                ->dehydrated(fn () => self::enabled())
                ->required(fn () => self::enabled())
                ->rules(['nullable', 'uuid']),
            Hidden::make('payment_date')
                ->default(fn () => now('Asia/Aden')->toDateString())
                ->dehydrated(fn () => self::enabled())
                ->required(fn () => self::enabled())
                ->rules(['nullable', 'date_format:Y-m-d']),
            Select::make('cash_session_id')
                ->label('صندوق التحصيل الأصلي')
                ->placeholder('بدون صندوق محصّل')
                ->options(fn () => CashSession::query()->open()->where('user_id', auth()->id())
                    ->orderBy('id')->get()->mapWithKeys(fn ($session) => [
                        $session->id => '#'.$session->id.' — '.$session->session_date->toDateString(),
                    ])->all())
                ->visible(fn () => self::enabled())
                ->dehydrated(fn () => self::enabled())
                ->helperText('اختر صندوقك المفتوح صراحةً. لا ينقل النظام السند إلى صندوق آخر.'),
        ];
    }

    public static function methods(): array
    {
        return self::enabled() ? ['cash' => 'نقدي'] : [
            'cash' => 'نقدي', 'bank_transfer' => 'تحويل بنكي', 'check' => 'شيك',
        ];
    }

    public static function collect(Subscriber $subscriber, array $data, ?int $invoiceId = null): Payment
    {
        Gate::authorize('create', Payment::class);
        $entry = app(ProspectiveCashReceiptEntry::class);
        if ($entry->enabled()) {
            try {
                return $entry->collect(auth()->user(), $subscriber, $data, $invoiceId);
            } catch (ConflictHttpException $error) {
                // Keep the mounted modal and its original operation identity.
                throw ValidationException::withMessages(['data' => $error->getMessage()]);
            }
        }
        return app(PaymentService::class)->collectPayment(
            subscriber: $subscriber,
            amount: $data['amount'],
            invoiceId: $invoiceId ?? ($data['invoice_id'] ?? null),
            paymentMethod: $data['payment_method'],
            referenceNumber: $data['reference_number'] ?? null,
            notes: $data['notes'] ?? null,
            reason: $data['reason'] ?? null,
            userId: auth()->id(),
        );
    }

    private static function enabled(): bool
    {
        return app(ProspectiveCashReceiptEntry::class)->enabled();
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\User;
use App\Support\FinancialAmount;
use DomainException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Shared candidate boundary for all ordinary cash receipt HTTP entrypoints.
 * Claim submission/settlement remains a distinct workflow; no fallback.
 */
final class ProspectiveCashReceiptEntry
{
    public function enabled(): bool
    {
        if (config('financial_candidate.cash_receipts') !== true) return false;
        abort_unless(app()->environment(['testing', 'staging']), 503, 'مسار التحصيل التجريبي غير متاح في الإنتاج.');
        return true;
    }

    public function collect(User $actor, Subscriber $subscriber, array $data, ?int $invoiceId = null): Payment
    {
        abort_unless($this->enabled(), 503);
        if (($data['payment_method'] ?? null) !== 'cash') {
            throw ValidationException::withMessages([
                'payment_method' => 'التحصيل غير النقدي يتطلب طلباً مستقلاً وإثبات وصول الأموال.',
            ]);
        }
        if (! is_string($data['sync_uuid'] ?? null) || ! Str::isUuid($data['sync_uuid'])) {
            throw ValidationException::withMessages(['sync_uuid' => 'يلزم معرّف مزامنة ثابت وصالح.']);
        }
        $date = $data['payment_date'] ?? null;
        if ($date !== null && (! is_string($date) || ! preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', $date))) {
            throw ValidationException::withMessages(['payment_date' => 'يجب تحديد تاريخ القبض بصيغة يوم محاسبي ثابتة.']);
        }
        $minor = FinancialAmount::validated($data['amount']);
        try {
            return app(GatedCashReceiptWriter::class)->collect(
                $actor, (int) $subscriber->id, $minor->decimal(), strtolower($data['sync_uuid']),
                now()->toDateTimeImmutable(),
                isset($data['cash_session_id']) ? (int) $data['cash_session_id'] : null,
                $invoiceId ?? (isset($data['invoice_id']) ? (int) $data['invoice_id'] : null),
                isset($data['meter_id']) ? (int) $data['meter_id'] : null,
                $data['reference_number'] ?? null, $data['reason'] ?? null, $data['notes'] ?? null,
                $date, (bool) ($data['deferred_sync'] ?? false),
            )['payment'];
        } catch (DomainException $error) {
            throw new ConflictHttpException('تعذر ترحيل سند القبض: '.$error->getMessage(), $error);
        }
    }
}

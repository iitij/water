<?php

namespace App\Http\Controllers\Api;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use Illuminate\Http\Request;

/**
 * البحث الشامل — يعادل شريط البحث العلوي في لوحة Filament.
 *
 * يستقبل `q` ويعيد مجموعات نتائج (مشتركون، فواتير، عدادات، سندات قبض، مربعات).
 * كل مجموعة لا تُعاد إلا إذا كان المستخدم يملك صلاحية عرض قائمتها، والصلاحية
 * تُفحص بنفس قواعد `authorizeApi` (Shield + الأسماء القديمة + الأدوار المدمجة).
 */
class SearchController extends ApiController
{
    /** أقل عدد أحرف مقبول لتنفيذ البحث. */
    private const MIN_TERM_LENGTH = 2;

    public function index(Request $request)
    {
        $raw = trim((string) $request->query('q', ''));
        $term = trim($this->normalizeDigits($raw));
        $limit = max(1, min(10, (int) $request->query('limit', 5)));

        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return response()->json([
                'query' => $raw,
                'min_length' => self::MIN_TERM_LENGTH,
                'groups' => [],
            ]);
        }

        $builders = [
            'subscriber' => ['label' => 'المشتركون', 'resource' => Subscriber::class, 'items' => fn () => $this->subscribers($term, $limit)],
            'invoice' => ['label' => 'الفواتير', 'resource' => Invoice::class, 'items' => fn () => $this->invoices($term, $limit)],
            'meter' => ['label' => 'العدادات', 'resource' => Meter::class, 'items' => fn () => $this->meters($term, $limit)],
            'payment' => ['label' => 'سندات القبض', 'resource' => Payment::class, 'items' => fn () => $this->payments($term, $limit)],
            'square' => ['label' => 'المربعات', 'resource' => Square::class, 'items' => fn () => $this->squares($term, $limit)],
        ];

        $groups = [];
        foreach ($builders as $type => $builder) {
            if (! $this->allowsApi($builder['resource'], 'viewAny')) {
                continue;
            }

            $items = ($builder['items'])();
            if ($items === []) {
                continue;
            }

            $groups[] = [
                'type' => $type,
                'label' => $builder['label'],
                'items' => $items,
            ];
        }

        return response()->json([
            'query' => $raw,
            'min_length' => self::MIN_TERM_LENGTH,
            'groups' => $groups,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function subscribers(string $term, int $limit): array
    {
        return Subscriber::query()
            ->with('square:id,name')
            ->where(function ($query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            })
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Subscriber $subscriber) => [
                'id' => $subscriber->id,
                'title' => $subscriber->name,
                'subtitle' => $this->joinParts([$subscriber->square?->name, $subscriber->phone]),
                'badge' => $this->subscriberStatusLabel($subscriber->status),
                'subscriber_id' => $subscriber->id,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function invoices(string $term, int $limit): array
    {
        return Invoice::query()
            ->with(['subscriber:id,name,phone'])
            ->where(function ($query) use ($term) {
                $query->where('bill_number', 'like', "%{$term}%")
                    ->orWhereHas('subscriber', function ($subscriberQuery) use ($term) {
                        $subscriberQuery->where('name', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%");
                    });
            })
            ->latest('bill_date')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'title' => 'فاتورة ' . $invoice->bill_number,
                'subtitle' => $this->joinParts([
                    $invoice->subscriber?->name,
                    $invoice->bill_date?->format('Y-m-d'),
                ]),
                'badge' => $this->invoiceStatusLabel($invoice->status),
                'amount' => (float) $invoice->total_amount,
                'subscriber_id' => $invoice->subscriber_id,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function meters(string $term, int $limit): array
    {
        return Meter::query()
            ->with(['subscriber:id,name,square_id', 'subscriber.square:id,name'])
            ->where(function ($query) use ($term) {
                $query->where('unique_code', 'like', "%{$term}%")
                    ->orWhere('path_number', 'like', "%{$term}%")
                    ->orWhere('tenant_name', 'like', "%{$term}%")
                    ->orWhere('tenant_phone', 'like', "%{$term}%")
                    ->orWhereHas('subscriber', function ($subscriberQuery) use ($term) {
                        $subscriberQuery->where('name', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%");
                    });
            })
            ->orderBy('unique_code')
            ->limit($limit)
            ->get()
            ->map(fn (Meter $meter) => [
                'id' => $meter->id,
                'title' => 'عداد ' . $meter->unique_code,
                'subtitle' => $this->joinParts([
                    $meter->subscriber?->name,
                    $meter->subscriber?->square?->name,
                    $meter->path_number !== null ? 'مسار ' . $meter->path_number : null,
                    $meter->tenant_name,
                ]),
                'badge' => $meter->status === 'active' ? 'نشط' : ($meter->status === 'inactive' ? 'موقوف' : $meter->status),
                'subscriber_id' => $meter->subscriber_id,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function payments(string $term, int $limit): array
    {
        return Payment::query()
            ->with(['subscriber:id,name,phone'])
            ->where(function ($query) use ($term) {
                $query->where('receipt_number', 'like', "%{$term}%")
                    ->orWhere('reference_number', 'like', "%{$term}%")
                    ->orWhereHas('subscriber', function ($subscriberQuery) use ($term) {
                        $subscriberQuery->where('name', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%");
                    });
            })
            ->latest('payment_date')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'title' => 'سند قبض ' . $payment->receipt_number,
                'subtitle' => $this->joinParts([
                    $payment->subscriber?->name,
                    $payment->payment_date?->format('Y-m-d'),
                ]),
                'badge' => $this->paymentMethodLabel($payment->payment_method),
                'amount' => (float) $payment->amount,
                'subscriber_id' => $payment->subscriber_id,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function squares(string $term, int $limit): array
    {
        return Square::query()
            ->withCount('subscribers')
            ->where('name', 'like', "%{$term}%")
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Square $square) => [
                'id' => $square->id,
                'title' => $square->name,
                'subtitle' => $this->joinParts([
                    'مربع سكني',
                    $square->subscribers_count . ' مشترك',
                ]),
                'badge' => null,
                'subscriber_id' => null,
            ])
            ->all();
    }

    /** @param array<int, string|null> $parts */
    private function joinParts(array $parts): string
    {
        $clean = array_values(array_filter($parts, fn ($part) => is_string($part) && trim($part) !== ''));

        return implode(' • ', $clean);
    }

    private function subscriberStatusLabel(?string $status): string
    {
        return match ($status) {
            'active' => 'نشط',
            'inactive' => 'موقوف',
            'suspended' => 'معلّق',
            default => (string) $status,
        };
    }

    private function invoiceStatusLabel(?string $status): string
    {
        return match ($status) {
            'paid' => 'مدفوعة',
            'partially_paid' => 'مدفوعة جزئياً',
            'overdue' => 'متأخرة',
            'issued' => 'صادرة',
            'unpaid' => 'غير مدفوعة',
            default => (string) $status,
        };
    }

    private function paymentMethodLabel(?string $method): string
    {
        return match ($method) {
            'cash' => 'نقداً',
            'bank_transfer' => 'حوالة',
            'check' => 'شيك',
            default => (string) ($method ?? 'قبض'),
        };
    }
}

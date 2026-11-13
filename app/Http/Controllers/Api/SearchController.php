<?php

namespace App\Http\Controllers\Api;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
        $minLength = ctype_digit($term) ? 1 : self::MIN_TERM_LENGTH;

        if (mb_strlen($term) < $minLength) {
            return response()->json([
                'query' => $raw,
                'min_length' => $minLength,
                'groups' => [],
            ]);
        }

        $builders = [
            'subscriber' => ['label' => 'المشتركون', 'resource' => Subscriber::class, 'items' => fn () => $this->subscribers($term, $limit)],
            'meter' => ['label' => 'العدادات', 'resource' => Meter::class, 'items' => fn () => $this->meters($term, $limit)],
            'invoice' => ['label' => 'الفواتير', 'resource' => Invoice::class, 'items' => fn () => $this->invoices($term, $limit)],
            'payment' => ['label' => 'سندات القبض', 'resource' => Payment::class, 'items' => fn () => $this->payments($term, $limit)],
            'square' => ['label' => 'المربعات', 'resource' => Square::class, 'items' => fn () => $this->squares($term, $limit)],
        ];

        if (ctype_digit($term) || $this->parseCompoundSearch($term)) {
            unset($builders['square']);
        }

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
            'min_length' => $minLength,
            'groups' => $groups,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function subscribers(string $term, int $limit): array
    {
        return Subscriber::query()
            ->with(['square:id,name,code', 'meters:id,subscriber_id,path_number'])
            ->where(function ($query) use ($term) {
                if ($this->routeTerm($query, $term)) {
                    return;
                }
                $query->where('name', 'like', "%{$term}%")
                    ->orWhereHas('square', fn ($square) => $square->where('code', 'like', "%{$term}%"))
                    ->orWhere('phone', 'like', "%{$term}%");
            })
            ->orderBy('name')
            ->tap(fn ($query) => $this->applySorting($query, request(), self::LIST_SORTS['subscribers']))
            ->limit($limit)
            ->get()
            ->map(fn (Subscriber $subscriber) => [
                ...$this->resultLocation($subscriber),
                'id' => $subscriber->id,
                'title' => $subscriber->name,
                'subtitle' => $this->joinParts([$subscriber->square?->display_name, $subscriber->phone]),
                'badge' => $this->subscriberStatusLabel($subscriber->status),
                'subscriber_id' => $subscriber->id,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function invoices(string $term, int $limit): array
    {
        return Invoice::query()
            ->with(['subscriber:id,name,phone,square_id', 'subscriber.square:id,name,code', 'subscriber.meters:id,subscriber_id,path_number', 'meter:id,path_number'])
            ->where(function ($query) use ($term) {
                if ($this->routeTerm($query, $term)) {
                    return;
                }
                $query->where('bill_number', 'like', "%{$term}%")
                    ->orWhereHas('subscriber.square', fn ($square) => $square->where('code', 'like', "%{$term}%"))
                    ->orWhereHas('subscriber', function ($subscriberQuery) use ($term) {
                        $subscriberQuery->where('name', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%");
                    });
            })
            ->latest('bill_date')
            ->latest('id')
            ->tap(fn ($query) => $this->applySorting($query, request(), self::LIST_SORTS['invoices']))
            ->limit($limit)
            ->get()
            ->map(fn (Invoice $invoice) => [
                ...$this->resultLocation($invoice),
                'id' => $invoice->id,
                'title' => 'فاتورة '.$invoice->bill_number,
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
            ->with(['subscriber:id,name,square_id', 'subscriber.square:id,name,code'])
            ->where(function ($query) use ($term) {
                if ($this->routeTerm($query, $term)) {
                    return;
                }
                $query->where('unique_code', 'like', "%{$term}%")
                    ->orWhereHas('subscriber.square', fn ($square) => $square->where('code', 'like', "%{$term}%"))
                    ->orWhere('path_number', 'like', "%{$term}%")
                    ->orWhereHas('subscriber', function ($subscriberQuery) use ($term) {
                        $subscriberQuery->where('name', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%");
                    });
            })
            ->orderBy('unique_code')
            ->tap(fn ($query) => $this->applySorting($query, request(), self::LIST_SORTS['meters']))
            ->limit($limit)
            ->get()
            ->map(fn (Meter $meter) => [
                ...$this->resultLocation($meter),
                'id' => $meter->id,
                'title' => 'عداد '.$meter->unique_code,
                'subtitle' => $this->joinParts([
                    $meter->subscriber?->name,
                    $meter->subscriber?->square?->display_name,
                    $meter->path_number !== null ? 'مسار '.$meter->path_number : null,
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
            ->with(['subscriber:id,name,phone,square_id', 'subscriber.square:id,name,code', 'subscriber.meters:id,subscriber_id,path_number', 'meter:id,path_number'])
            ->where(function ($query) use ($term) {
                if ($this->routeTerm($query, $term)) {
                    return;
                }
                $query->where('receipt_number', 'like', "%{$term}%")
                    ->orWhereHas('subscriber.square', fn ($square) => $square->where('code', 'like', "%{$term}%"))
                    ->orWhere('reference_number', 'like', "%{$term}%")
                    ->orWhereHas('subscriber', function ($subscriberQuery) use ($term) {
                        $subscriberQuery->where('name', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%");
                    });
            })
            ->latest('payment_date')
            ->latest('id')
            ->tap(fn ($query) => $this->applySorting($query, request(), self::LIST_SORTS['payments']))
            ->limit($limit)
            ->get()
            ->map(fn (Payment $payment) => [
                ...$this->resultLocation($payment),
                'id' => $payment->id,
                'title' => 'سند قبض '.$payment->receipt_number,
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
            ->where(fn ($query) => $query->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"))
            ->orderBy('name')
            ->tap(fn ($query) => $this->applySorting($query, request(), self::LIST_SORTS['squares']))
            ->limit($limit)
            ->get()
            ->map(fn (Square $square) => [
                ...$this->resultLocation($square),
                'id' => $square->id,
                'title' => $square->display_name,
                'subtitle' => $this->joinParts([
                    'مربع سكني',
                    $square->subscribers_count.' مشترك',
                ]),
                'badge' => null,
                'subscriber_id' => null,
            ])
            ->all();
    }

    /** Route facets narrow records before limits; linked meters stay isolated. */
    private function routeTerm(Builder $query, string $term): bool
    {
        $normalized = $this->normalizeDigits($term);
        $compound = $this->parseCompoundSearch($normalized);
        $number = $compound[1] ?? (ctype_digit($normalized) ? (int) $normalized : null);
        if ($number === null) {
            return false;
        }
        $match = function ($builder) use ($number) {
            $table = $builder->getModel()->getTable();
            if ($table === 'meters') {
                $builder->where('path_number', $number);
            } elseif ($table === 'subscribers') {
                $builder->whereHas('meters', fn ($meter) => $meter->where('path_number', $number));
            } else {
                $builder->where(fn ($record) => $record->whereHas('meter', fn ($meter) => $meter->where('path_number', $number))
                    ->orWhere(fn ($general) => $general->whereNull('meter_id')->whereHas('subscriber.meters', fn ($meter) => $meter->where('path_number', $number))));
            }
        };
        if ($compound !== null) {
            $match($query);
            if ($query->getModel()->getTable() === 'subscribers') {
                $query->where('square_id', $compound[0]);
            } else {
                $query->whereHas('subscriber', fn ($subscriber) => $subscriber->where('square_id', $compound[0]));
            }

            return true;
        }
        $match($query);

        return true;
    }

    private function resultLocation(Model $record): array
    {
        if ($record instanceof Square) {
            return ['created_at' => $record->created_at?->toISOString()];
        }
        $subscriber = $record instanceof Subscriber ? $record : $record->subscriber;
        $paths = $record instanceof Meter ? collect([$record->path_number])
            : ($record instanceof Subscriber || $record->meter_id === null
                ? ($subscriber?->meters ?? collect())->pluck('path_number')
                : collect([$record->meter?->path_number]));

        return [
            'created_at' => $record->created_at?->toISOString(),
            'square_id' => $subscriber?->square_id,
            'square_name' => $subscriber?->square?->name,
            'square_code' => $subscriber?->square?->code,
            'path_numbers' => $paths->filter(fn ($path) => $path !== null)->map(fn ($path) => (int) $path)->unique()->sort()->values()->all(),
        ];
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

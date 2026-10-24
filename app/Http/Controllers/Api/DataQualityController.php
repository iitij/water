<?php

namespace App\Http\Controllers\Api;

use App\Models\Meter;
use App\Models\Reading;
use App\Models\Subscriber;
use App\Models\Tariff;
use Illuminate\Http\Request;

/**
 * جودة البيانات: قائمة مشكلات قابلة للإصلاح بدل تقرير عام (خطة 2.0 — الفصل 6.1).
 *
 * الفكرة أن يفتح المشرف الشاشة فيرى «12 مشتركاً بلا هاتف» و«4 عدادات بلا قراءة
 * منذ 45 يوماً» مع أمثلة تسمح بالانتقال المباشر للتصحيح. لا نُخزّن نتائج:
 * الاستعلامات خفيفة ومفهرسة، وتخزينها يعني بيانات قديمة تَنتحل صفة الحقيقة.
 */
class DataQualityController extends ApiController
{
    /** عتبة «قراءة قديمة» بالأيام — قابلة للتعديل من الطلب للاختبار والتقارير. */
    private const DEFAULT_STALE_DAYS = 45;

    /** عدد الأمثلة لكل مشكلة: ما يكفي للفت الانتباه بلا إغراق الشاشة. */
    private const SAMPLE_LIMIT = 5;

    public function index(Request $request)
    {
        $this->authorizeSystem('ViewAny:Subscriber');

        $squareId = $request->filled('square_id') ? $request->integer('square_id') : null;
        $staleDays = max(7, min(365, $request->integer('stale_days', self::DEFAULT_STALE_DAYS)));

        $issues = [
            $this->subscribersWithoutPhone($squareId),
            $this->subscribersWithoutMeters($squareId),
            $this->metersWithoutPathNumber($squareId),
            $this->metersWithZeroReading($squareId),
            $this->metersStaleReadings($squareId, $staleDays),
            $this->metersWithoutActiveTariff($squareId),
            $this->readingsConsumptionMismatch($squareId),
        ];

        $total = array_sum(array_map(fn (array $issue) => $issue['count'], $issues));

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'stale_days' => $staleDays,
            'total' => $total,
            'issues' => $issues,
        ]);
    }

    /** مشتركون نشطون بلا رقم هاتف — لا يمكن إرسال تذكير واتساب لهم. */
    private function subscribersWithoutPhone(?int $squareId): array
    {
        $base = fn () => Subscriber::query()
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('phone')->orWhere('phone', ''))
            ->when($squareId, fn ($query) => $query->where('square_id', $squareId));

        $count = $base()->count();
        $samples = $base()
            ->orderBy('id')
            ->limit(self::SAMPLE_LIMIT)
            ->get(['id', 'name', 'square_id'])
            ->map(fn (Subscriber $subscriber) => [
                'id' => $subscriber->id,
                'label' => $subscriber->name,
                'route' => 'subscribers',
            ])
            ->all();

        return $this->issue(
            code: 'subscriber_without_phone',
            title: 'مشتركون بلا رقم هاتف',
            detail: 'لا يمكن إرسال تذكير واتساب أو استدعاؤهم عند الطارئ.',
            severity: 'high',
            count: $count,
            samples: $samples,
        );
    }

    /** مشتركون نشطون بلا أي عداد — لا قراءة ولا فاتورة لهم. */
    private function subscribersWithoutMeters(?int $squareId): array
    {
        $base = fn () => Subscriber::query()
            ->where('status', 'active')
            ->whereDoesntHave('meters')
            ->when($squareId, fn ($query) => $query->where('square_id', $squareId));

        $count = $base()->count();
        $samples = $base()
            ->orderBy('id')
            ->limit(self::SAMPLE_LIMIT)
            ->get(['id', 'name'])
            ->map(fn (Subscriber $subscriber) => [
                'id' => $subscriber->id,
                'label' => $subscriber->name,
                'route' => 'subscribers',
            ])
            ->all();

        return $this->issue(
            code: 'subscriber_without_meter',
            title: 'مشتركون بلا عدّاد',
            detail: 'حساب مفتوح بلا عدّاد: لا يمكن إصدار فاتورة أو قراءة.',
            severity: 'high',
            count: $count,
            samples: $samples,
        );
    }

    /** عدادات نشطة بلا رقم مسار — تختل ترتيب الجولة الميدانية. */
    private function metersWithoutPathNumber(?int $squareId): array
    {
        $base = fn () => Meter::query()
            ->where('status', 'active')
            ->whereNull('path_number')
            ->when($squareId, fn ($query) => $query->whereHas(
                'subscriber',
                fn ($inner) => $inner->where('square_id', $squareId),
            ));

        $count = $base()->count();
        $samples = $base()
            ->orderBy('id')
            ->limit(self::SAMPLE_LIMIT)
            ->get(['id', 'unique_code', 'subscriber_id'])
            ->map(fn (Meter $meter) => [
                'id' => $meter->id,
                'label' => $meter->unique_code,
                'route' => 'meters',
            ])
            ->all();

        return $this->issue(
            code: 'meter_without_path',
            title: 'عدادات بلا رقم مسار',
            detail: 'وضع المسار يرتّب الجولة برقم المسار؛ بدونها يظهر العداد في النهاية.',
            severity: 'medium',
            count: $count,
            samples: $samples,
        );
    }

    /** عدادات بقراءة أولية صفرية — عادةً خطأ إدخال عند التركيب. */
    private function metersWithZeroReading(?int $squareId): array
    {
        $base = fn () => Meter::query()
            ->where('status', 'active')
            ->where('initial_reading', 0)
            ->whereDoesntHave('readings')
            ->when($squareId, fn ($query) => $query->whereHas(
                'subscriber',
                fn ($inner) => $inner->where('square_id', $squareId),
            ));

        $count = $base()->count();
        $samples = $base()
            ->orderBy('id')
            ->limit(self::SAMPLE_LIMIT)
            ->get(['id', 'unique_code'])
            ->map(fn (Meter $meter) => [
                'id' => $meter->id,
                'label' => $meter->unique_code,
                'route' => 'meters',
            ])
            ->all();

        return $this->issue(
            code: 'meter_zero_initial_reading',
            title: 'عدادات بقراءة أولية صفرية',
            detail: 'تركيب جديد بقراءة 0: تأكد من قراءة العدّاد الفعلية قبل أول فاتورة.',
            severity: 'low',
            count: $count,
            samples: $samples,
        );
    }

    /** عدادات نشطة لم تُقرأ منذ [staleDays] يوماً أو لم تُقرأ إطلاقاً. */
    private function metersStaleReadings(?int $squareId, int $staleDays): array
    {
        $cutoff = now()->subDays($staleDays)->toDateString();

        $base = fn () => Meter::query()
            ->where('status', 'active')
            ->whereDoesntHave('readings', fn ($query) => $query->where('reading_date', '>=', $cutoff))
            ->when($squareId, fn ($query) => $query->whereHas(
                'subscriber',
                fn ($inner) => $inner->where('square_id', $squareId),
            ));

        $count = $base()->count();
        $samples = $base()
            ->withMax('readings as last_reading_date', 'reading_date')
            ->orderBy('id')
            ->limit(self::SAMPLE_LIMIT)
            ->get(['id', 'unique_code'])
            ->map(fn (Meter $meter) => [
                'id' => $meter->id,
                'label' => $meter->unique_code,
                'meta' => $meter->last_reading_date ?? 'لا توجد قراءة',
                'route' => 'meters',
            ])
            ->all();

        return $this->issue(
            code: 'meter_stale_reading',
            title: 'عدادات بلا قراءة حديثة',
            detail: "لم تُسجّل قراءة خلال {$staleDays} يوماً — قد تكون الجولة لم تغطِّها.",
            severity: 'high',
            count: $count,
            samples: $samples,
        );
    }

    /** لا توجد تعرفة فعّالة — عندها تُحسب الفواتير بسعر صفر أو تفشل. */
    private function metersWithoutActiveTariff(?int $squareId): array
    {
        $activeTariffs = Tariff::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('start_date')->orWhere('start_date', '<=', now()))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', now()))
            ->count();

        if ($activeTariffs > 0) {
            return $this->issue(
                code: 'no_active_tariff',
                title: 'تعرفة فعّالة',
                detail: 'توجد تعرفة سارية — لا إجراء مطلوب.',
                severity: 'info',
                count: 0,
                samples: [],
            );
        }

        $meters = Meter::query()
            ->where('status', 'active')
            ->when($squareId, fn ($query) => $query->whereHas(
                'subscriber',
                fn ($inner) => $inner->where('square_id', $squareId),
            ))
            ->count();

        return $this->issue(
            code: 'no_active_tariff',
            title: 'لا توجد تعرفة فعّالة',
            detail: 'كل العدادات النشطة ستُفوتر بسعر صفر حتى تُضاف تعرفة سارية.',
            severity: 'high',
            count: $meters,
            samples: [],
        );
    }

    /** استهلاك مسجّل لا يطابق الفرق بين القراءتين — أثر تعديل يدوي أو خلل إدخال. */
    private function readingsConsumptionMismatch(?int $squareId): array
    {
        $base = fn () => Reading::query()
            ->when($squareId, fn ($query) => $query->whereHas(
                'meter.subscriber',
                fn ($inner) => $inner->where('square_id', $squareId),
            ))
            ->whereRaw('ABS(consumption_units - (current_reading - previous_reading)) > 0.01');

        $count = $base()->count();
        $samples = $base()
            ->latest('reading_date')
            ->limit(self::SAMPLE_LIMIT)
            ->get(['id', 'meter_id', 'reading_date'])
            ->map(fn (Reading $reading) => [
                'id' => $reading->id,
                'label' => 'قراءة #'.$reading->id,
                'meta' => optional($reading->reading_date)->toDateString(),
                'route' => 'readings',
            ])
            ->all();

        return $this->issue(
            code: 'reading_consumption_mismatch',
            title: 'قراءات باستهلاك غير مطابق',
            detail: 'الاستهلاك المسجّل لا يساوي الفرق بين القراءتين — راجع التعديلات اليدوية.',
            severity: 'medium',
            count: $count,
            samples: $samples,
        );
    }

    private function issue(
        string $code,
        string $title,
        string $detail,
        string $severity,
        int $count,
        array $samples,
    ): array {
        return [
            'code' => $code,
            'title' => $title,
            'detail' => $detail,
            'severity' => $severity,
            'count' => $count,
            'samples' => $samples,
        ];
    }
}

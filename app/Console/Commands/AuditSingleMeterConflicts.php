<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only preflight before enforcing a single current meter per subscriber.
 * Does not alter assignments, invoices, balances or payments.
 */
class AuditSingleMeterConflicts extends Command
{
    protected $signature = 'water:audit-single-meter';
    protected $description = 'عرض المشتركين الذين يرتبطون بأكثر من عداد حالي قبل ترحيل قاعدة البيانات';

    public function handle(): int
    {
        $conflictingSubscribers = DB::table('meters')
            ->select('subscriber_id')
            ->selectRaw('COUNT(*) as current_meter_count')
            ->whereNull('removed_at')
            ->whereNull('deleted_at')
            ->groupBy('subscriber_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('subscriber_id')
            ->get();

        if ($conflictingSubscribers->isEmpty()) {
            $this->info('لا توجد ارتباطات متعددة لعدادات حالية. فحص التعارضات ناجح.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($conflictingSubscribers as $conflict) {
            $meterIds = DB::table('meters')
                ->where('subscriber_id', $conflict->subscriber_id)
                ->whereNull('removed_at')
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all();

            $pendingReadings = DB::table('readings')
                ->whereIn('meter_id', $meterIds)
                ->whereNull('deleted_at')
                ->where('is_invoiced', false)
                ->count();

            $unpaidInvoices = DB::table('invoices')
                ->whereIn('meter_id', $meterIds)
                ->whereNull('deleted_at')
                ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                ->count();

            $rows[] = [
                (int) $conflict->subscriber_id,
                (int) $conflict->current_meter_count,
                implode(', ', $meterIds),
                $pendingReadings,
                $unpaidInvoices,
            ];
        }

        $this->error('توجد تعارضات تحتاج تسوية يدوية قبل تفعيل قيد العداد الواحد.');
        $this->table([
            'subscriber_id',
            'current_meters',
            'meter_ids',
            'uninvoiced_readings',
            'open_meter_invoices',
        ], $rows);

        $this->warn('لا تحذف العدادات أو تنقل فواتيرها آلياً؛ راجع التاريخ المالي وحدد العداد الحالي ثم سجل removed_at على العداد السابق بعد التسوية.');

        return self::FAILURE;
    }
}

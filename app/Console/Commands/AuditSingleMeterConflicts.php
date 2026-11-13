<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only audit for one physically stored meter per subscriber.
 * Removed and soft-deleted rows also violate the owner-selected model.
 */
class AuditSingleMeterConflicts extends Command
{
    protected $signature = 'water:audit-single-meter';
    protected $description = 'التحقق من عدم وجود عدادات زائدة أو تاريخية للمشترك';

    public function handle(): int
    {
        $conflicts = DB::table('meters')
            ->select('subscriber_id')
            ->selectRaw('COUNT(*) as meter_count')
            ->groupBy('subscriber_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('subscriber_id')
            ->get();

        $obsolete = DB::table('meters')
            ->whereNotNull('removed_at')
            ->orWhereNotNull('deleted_at')
            ->get(['id', 'subscriber_id', 'removed_at', 'deleted_at']);

        if ($conflicts->isEmpty() && $obsolete->isEmpty()) {
            $this->info('نجاح: لا توجد عدادات مكررة أو منزوعة أو محذوفة منطقياً.');

            return self::SUCCESS;
        }

        if ($conflicts->isNotEmpty()) {
            $this->error('مشتركون لهم أكثر من سجل عداد:');
            $this->table(['subscriber_id', 'meter_count'],
                $conflicts->map(fn ($c) => [(int) $c->subscriber_id, (int) $c->meter_count])->all());
        }
        if ($obsolete->isNotEmpty()) {
            $this->error('عدادات تاريخية أو محذوفة منطقياً ما تزال في قاعدة البيانات:');
            $this->table(['meter_id', 'subscriber_id', 'removed_at', 'deleted_at'],
                $obsolete->map(fn ($m) => [$m->id, $m->subscriber_id, $m->removed_at, $m->deleted_at])->all());
        }

        return self::FAILURE;
    }
}

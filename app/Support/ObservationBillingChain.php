<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/** Validate the pending physical-observation suffix before pricing new charges. */
final class ObservationBillingChain
{
    /** Rows are active, same-meter raw database records, locked by the caller. */
    public static function assertValid(array $rows, array $pendingIds, mixed $baselineDate, mixed $initial): void
    {
        $selected = array_fill_keys($pendingIds, true);
        $pending = array_values(array_filter($rows, static fn (array $row): bool => isset($selected[$row['id']])));
        if (count($pending) !== count($selected)) {
            throw new InvalidArgumentException('تغيرت القراءات المحددة للفوترة.');
        }
        $observations = array_filter($pending, static fn (array $row): bool => $row['period_basis'] === 'observation');
        // Preserve the existing legacy-calendar-only billing contract. Do not
        // reinterpret calendar periods as physical observation intervals.
        if ($pending !== [] && count(array_filter($pending, static fn (array $row): bool => $row['period_basis'] === 'legacy_calendar')) === count($pending)) {
            return;
        }
        if (count($observations) !== count($pending)) {
            throw new InvalidArgumentException('لا يمكن فوترة سلسلة مختلطة أو غير معروفة الأساس دون تدقيق الفترات.');
        }
        if ($pending === []) {
            return;
        }

        $dated = [];
        $actualCounts = [];
        foreach ($rows as $row) {
            $date = self::date($row['actual_reading_date'] ?? $row['reading_date'] ?? null);
            $dated[] = ['date' => $date, 'row' => $row];
            $actualCounts[$date] = ($actualCounts[$date] ?? 0) + 1;
        }
        usort($dated, static fn (array $a, array $b): int => [$a['date'], $a['row']['id']] <=> [$b['date'], $b['row']['id']]);

        $previous = null;
        $seenPending = false;
        foreach ($dated as $entry) {
            $row = $entry['row'];
            $date = $entry['date'];
            $id = $row['id'];
            $isPending = isset($selected[$id]);
            if (! $isPending) {
                if ($seenPending) {
                    self::reject($id, 'انقطاع تسلسل القراءات: توجد قراءة غير محددة بعد قراءة معلقة.');
                }
                $previous = $entry;
                continue;
            }
            $seenPending = true;
            if ($actualCounts[$date] !== 1) {
                self::reject($id, 'تكرار التاريخ الفعلي للقراءة.');
            }
            if ($row['actual_reading_date'] === null || ! in_array($row['is_invoiced'], [false, 0, '0'], true)
                || $row['invoice_id'] !== null || $row['retired_meter_id'] !== null) {
                self::reject($id, 'حالة القراءة أو ارتباطها المالي غير صالح للفوترة الجديدة.');
            }
            $start = self::date($row['period_start']);
            $end = self::date($row['period_end']);
            if ($end !== $date || $start >= $end) {
                self::reject($id, 'الفترة لا تطابق تاريخ الملاحظة الفعلية.');
            }
            if ($previous !== null) {
                $prior = $previous['row'];
                $anchor = $previous['date'];
                if ($actualCounts[$anchor] !== 1) {
                    self::reject($id, 'تكرار تاريخ القراءة السابقة.');
                }
                if (! isset($selected[$prior['id']])) {
                    if (! in_array($prior['is_invoiced'], [true, 1, '1'], true) || $prior['invoice_id'] === null
                        || $prior['retired_meter_id'] !== null
                        || ! in_array($prior['period_basis'], ['observation', 'legacy_calendar'], true)
                        || ($prior['period_basis'] === 'observation' && self::date($prior['period_end']) !== $anchor)) {
                        self::reject($id, 'أساس القراءة السابقة غير متسق.');
                    }
                }
                $expected = ReadingQuantity::hundredths($prior['current_reading']);
            } else {
                $anchor = self::date($baselineDate);
                $expected = ReadingQuantity::hundredths($initial);
            }
            if ($start < $anchor) {
                self::reject($id, 'تداخل الفترات مع القراءة السابقة.');
            }
            $from = ReadingQuantity::hundredths($row['previous_reading']);
            $to = ReadingQuantity::hundredths($row['current_reading']);
            $units = ReadingQuantity::hundredths($row['consumption_units']);
            if ($start !== $anchor || $from !== $expected || $to < $from || $units !== $to - $from) {
                self::reject($id, 'انقطاع تسلسل القراءات أو عدم تطابق كمية القراءة السابقة والاستهلاك.');
            }
            $previous = $entry;
        }
    }

    private static function date(mixed $raw): string
    {
        // SQLite date columns may contain a midnight datetime representation.
        if (! is_string($raw) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})(?: 00:00:00)?\z/', $raw, $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('تاريخ تسلسل القراءات غير صالح أو غير متوفر.');
        }
        return substr($raw, 0, 10);
    }

    private static function reject(int|string $id, string $reason): never
    {
        throw new InvalidArgumentException("القراءة #{$id}: {$reason}");
    }
}

<?php

namespace App\Services;

use App\Models\Subscriber;
use App\Models\Invoice;
use App\Models\Reading;
use Carbon\Carbon;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Entity\Style\Color;

class DataExportService
{
    /**
     * تصدير بيانات المشتركين
     */
    public function exportSubscribers(?int $squareId = null, ?string $status = null): string
    {
        $query = Subscriber::with(['square', 'meters'])
            ->orderBy('square_id')
            ->orderBy('id');

        if ($squareId) {
            $query->where('square_id', $squareId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $subscribers = $query->get();

        $headerStyle = (new Style())
            ->setFontBold()
            ->setFontSize(11)
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor('1E3A8A'); // Dark Blue

        $headers = [
            'م',
            'اسم المشترك',
            'رقم الهاتف',
            'المربع السكني',
            'العنوان / الموقع',
            'كود العداد',
            'رقم المسار',
            'حالة العداد',
            'إجمالي الرصيد الدائن (ر.ي)',
            'إجمالي الرصيد المدين (ر.ي)',
            'صافي الرصيد (ر.ي)',
            'حالة المشترك',
            'تاريخ بدء الاشتراك',
            'تاريخ التسجيل',
        ];

        $tempPath = tempnam(sys_get_temp_dir(), 'subscribers_') . '.xlsx';
        $writer = new Writer();
        $writer->openToFile($tempPath);

        $headerCells = array_map(fn($h) => Cell::fromValue($h), $headers);
        $writer->addRow(new Row($headerCells, $headerStyle));

        $index = 1;
        $rowStyle = (new Style())->setFontSize(10);
        $altRowStyle = (new Style())->setFontSize(10)->setBackgroundColor('F8FAFC');

        foreach ($subscribers as $sub) {
            $primaryMeter = $sub->currentMeter;
            $allMeterCodes = $primaryMeter?->unique_code ?? '-';
            $allPaths = $primaryMeter?->path_number !== null ? (string)$primaryMeter->path_number : '-';

            $subscriberCredit = $sub->balance > 0 ? (float)$sub->balance : 0.0;
            $metersCredit = (float)$sub->meters->sum(fn($m) => $m->balance > 0 ? (float)$m->balance : 0.0);
            $totalCredit = $subscriberCredit + $metersCredit;

            $subscriberDebit = (float)$sub->getOutstandingBalance();
            $metersDebit = (float)$sub->meters->sum(fn($m) => (float)$m->getOutstandingBalance());
            $totalDebit = $subscriberDebit + $metersDebit;

            $netBalance = $totalCredit - $totalDebit;

            $meterStatus = match ($primaryMeter?->status) {
                'active' => 'نشط',
                'inactive' => 'غير نشط',
                'removed' => 'مسحوب',
                'suspended' => 'موقوف',
                default => ($primaryMeter ? ($primaryMeter->status ?? 'نشط') : 'لا يوجد عداد'),
            };

            $subStatus = match ($sub->status) {
                'active' => 'نشط',
                'inactive' => 'غير نشط',
                'suspended' => 'موقوف',
                default => $sub->status ?? 'نشط',
            };


            $startDate = $sub->subscription_start_date ? Carbon::parse($sub->subscription_start_date)->format('Y-m-d') : '-';
            $createdAt = $sub->created_at ? Carbon::parse($sub->created_at)->format('Y-m-d H:i') : '-';

            $cells = [
                Cell::fromValue($index++),
                Cell::fromValue($sub->name ?? ''),
                Cell::fromValue($sub->phone ?? ''),
                Cell::fromValue($sub->square?->name ?? 'غير محدد'),
                Cell::fromValue($sub->address ?? '-'),
                Cell::fromValue($allMeterCodes),
                Cell::fromValue($allPaths),
                Cell::fromValue($meterStatus),
                Cell::fromValue(round($totalCredit, 2)),
                Cell::fromValue(round($totalDebit, 2)),
                Cell::fromValue(round($netBalance, 2)),
                Cell::fromValue($subStatus),
                Cell::fromValue($startDate),
                Cell::fromValue($createdAt),
            ];

            $writer->addRow(new Row($cells, ($index % 2 === 0) ? $altRowStyle : $rowStyle));
        }

        $writer->close();
        return $tempPath;
    }

    /**
     * تصدير فواتير المشتركين
     */
    public function exportInvoices(?int $squareId = null, ?string $fromDate = null, ?string $toDate = null, ?string $status = null): string
    {
        $query = Invoice::with(['subscriber.square', 'meter'])
            ->orderBy('bill_date', 'desc')
            ->orderBy('id', 'desc');

        if ($squareId) {
            $query->whereHas('subscriber', fn($q) => $q->where('square_id', $squareId));
        }

        if ($fromDate) {
            $query->whereDate('bill_date', '>=', $fromDate);
        }

        if ($toDate) {
            $query->whereDate('bill_date', '<=', $toDate);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $invoices = $query->get();

        $headerStyle = (new Style())
            ->setFontBold()
            ->setFontSize(11)
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor('065F46'); // Deep Emerald Green

        $headers = [
            'م',
            'رقم الفاتورة',
            'تاريخ الفاتورة',
            'اسم المشترك',
            'رقم الهاتف',
            'المربع السكني',
            'كود العداد',
            'رقم المسار',
            'بداية فترة الاستهلاك',
            'نهاية فترة الاستهلاك',
            'رسوم الاستهلاك (ر.ي)',
            'قسط الاشتراك (ر.ي)',
            'متأخرات سابقة (ر.ي)',
            'إجمالي الفاتورة الحالية (ر.ي)',
            'إجمالي المطلوب مع المتأخرات (ر.ي)',
            'المسدد من الفاتورة (ر.ي)',
            'المتبقي المطلوب (ر.ي)',
            'حالة الفاتورة',
            'ملاحظات',
        ];

        $tempPath = tempnam(sys_get_temp_dir(), 'invoices_') . '.xlsx';
        $writer = new Writer();
        $writer->openToFile($tempPath);

        $headerCells = array_map(fn($h) => Cell::fromValue($h), $headers);
        $writer->addRow(new Row($headerCells, $headerStyle));

        $index = 1;
        $rowStyle = (new Style())->setFontSize(10);
        $altRowStyle = (new Style())->setFontSize(10)->setBackgroundColor('F0FDF4');

        foreach ($invoices as $inv) {
            $sub = $inv->subscriber;
            $meter = $inv->meter;

            $statusText = match ($inv->status) {
                'paid' => 'مسددة',
                'partially_paid' => 'مسددة جزئياً',
                'issued' => 'صادرة (مستحقة)',
                'overdue' => 'متأخرة',
                'draft' => 'مسودة',
                default => $inv->status ?? 'صادرة',
            };

            $totalWithPrev = (float)$inv->total_amount + (float)$inv->previous_balance;
            $remaining = max(0, $totalWithPrev - (float)$inv->amount_paid);

            $billDate = $inv->bill_date ? Carbon::parse($inv->bill_date)->format('Y-m-d') : '-';
            $periodStart = $inv->billing_period_start ? Carbon::parse($inv->billing_period_start)->format('Y-m-d') : '-';
            $periodEnd = $inv->billing_period_end ? Carbon::parse($inv->billing_period_end)->format('Y-m-d') : '-';

            $cells = [
                Cell::fromValue($index++),
                Cell::fromValue($inv->bill_number ?? (string)$inv->id),
                Cell::fromValue($billDate),
                Cell::fromValue($sub?->name ?? '-'),
                Cell::fromValue($sub?->phone ?? '-'),
                Cell::fromValue($sub?->square?->name ?? 'غير محدد'),
                Cell::fromValue($meter?->unique_code ?? '-'),
                Cell::fromValue($meter?->path_number !== null ? (string)$meter->path_number : '-'),
                Cell::fromValue($periodStart),
                Cell::fromValue($periodEnd),
                Cell::fromValue((float)($inv->consumption_fee ?? 0)),
                Cell::fromValue((float)($inv->previous_balance ?? 0)),
                Cell::fromValue((float)($inv->total_amount ?? 0)),
                Cell::fromValue(round($totalWithPrev, 2)),
                Cell::fromValue((float)($inv->amount_paid ?? 0)),
                Cell::fromValue(round($remaining, 2)),
                Cell::fromValue($statusText),
                Cell::fromValue($inv->notes ?? '-'),
            ];

            $writer->addRow(new Row($cells, ($index % 2 === 0) ? $altRowStyle : $rowStyle));
        }

        $writer->close();
        return $tempPath;
    }

    /**
     * تصدير قراءات العدادات
     */
    public function exportReadings(?int $squareId = null, ?string $fromDate = null, ?string $toDate = null, ?bool $isInvoiced = null): string
    {
        $query = Reading::with(['meter.subscriber.square', 'invoice', 'creator'])
            ->orderBy('reading_date', 'desc')
            ->orderBy('id', 'desc');

        if ($squareId) {
            $query->whereHas('meter.subscriber', fn($q) => $q->where('square_id', $squareId));
        }

        if ($fromDate) {
            $query->whereDate('reading_date', '>=', $fromDate);
        }

        if ($toDate) {
            $query->whereDate('reading_date', '<=', $toDate);
        }

        if ($isInvoiced !== null) {
            $query->where('is_invoiced', $isInvoiced);
        }

        $readings = $query->get();

        $headerStyle = (new Style())
            ->setFontBold()
            ->setFontSize(11)
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor('0E7490'); // Deep Teal/Cyan

        $headers = [
            'م',
            'تاريخ القراءة المعتمد',
            'التاريخ الفعلي للقراءة',
            'اسم المشترك',
            'رقم الهاتف',
            'المربع السكني',
            'كود العداد',
            'رقم المسار',
            'القراءة السابقة',
            'القراءة الحالية',
            'كمية الاستهلاك (وحدة/م³)',
            'بداية فترة الاستهلاك',
            'نهاية فترة الاستهلاك',
            'حالة الفوترة',
            'رقم الفاتورة المرتبطة',
            'مسجل القراءة',
            'تاريخ التسجيل بالمنظومة',
        ];

        $tempPath = tempnam(sys_get_temp_dir(), 'readings_') . '.xlsx';
        $writer = new Writer();
        $writer->openToFile($tempPath);

        $headerCells = array_map(fn($h) => Cell::fromValue($h), $headers);
        $writer->addRow(new Row($headerCells, $headerStyle));

        $index = 1;
        $rowStyle = (new Style())->setFontSize(10);
        $altRowStyle = (new Style())->setFontSize(10)->setBackgroundColor('F0FDFA');

        foreach ($readings as $rd) {
            $meter = $rd->meter;
            $sub = $meter?->subscriber;

            $invoicedText = $rd->is_invoiced ? 'مفوترة' : 'غير مفوترة';

            $readingDate = $rd->reading_date ? Carbon::parse($rd->reading_date)->format('Y-m-d') : '-';
            $actualDate = $rd->actual_reading_date ? Carbon::parse($rd->actual_reading_date)->format('Y-m-d') : '-';
            $createdAt = $rd->created_at ? Carbon::parse($rd->created_at)->format('Y-m-d H:i') : '-';

            $cells = [
                Cell::fromValue($index++),
                Cell::fromValue($readingDate),
                Cell::fromValue($actualDate),
                Cell::fromValue($sub?->name ?? '-'),
                Cell::fromValue($sub?->phone ?? '-'),
                Cell::fromValue($sub?->square?->name ?? 'غير محدد'),
                Cell::fromValue($meter?->unique_code ?? '-'),
                Cell::fromValue($meter?->path_number !== null ? (string)$meter->path_number : '-'),
                Cell::fromValue((float)($rd->previous_reading ?? 0)),
                Cell::fromValue((float)($rd->current_reading ?? 0)),
                Cell::fromValue((int)($rd->consumption_units ?? 0)),
                Cell::fromValue($rd->period_start ?? '-'),
                Cell::fromValue($rd->period_end ?? '-'),
                Cell::fromValue($invoicedText),
                Cell::fromValue($rd->invoice?->bill_number ?? ($rd->invoice_id ? (string)$rd->invoice_id : '-')),
                Cell::fromValue($rd->creator?->name ?? 'النظام'),
                Cell::fromValue($createdAt),
            ];

            $writer->addRow(new Row($cells, ($index % 2 === 0) ? $altRowStyle : $rowStyle));
        }

        $writer->close();
        return $tempPath;
    }
}

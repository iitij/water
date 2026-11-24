<?php

namespace App\Services;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

final class LedgerStatementExportService
{
    public function xlsx(array $statement): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ledger_statement_');
        if ($file === false) {
            throw new \RuntimeException('Unable to create statement export file.');
        }
        $writer = new Writer();
        try {
            $writer->openToFile($file);
            $header = (new Style())->setFontBold()->setBackgroundColor('E1EFF5');
            $row = static function (array $values, ?Style $style = null) use ($writer): void {
                $writer->addRow(new Row(array_map(fn ($value) => Cell::fromValue($value), $values), $style));
            };
            $row(['كشف حساب المشترك', $statement['subscriber']['name'], (string) $statement['subscriber']['id']], $header);
            $row(['من', $statement['from_date'], 'إلى', $statement['to_date']]);
            $row(['الأساس: تاريخ الترحيل؛ الموجب دائن والسالب مدين']);
            $row(['رصيد الافتتاح', $statement['opening_balance_minor'] / 100]);
            $row(['إجمالي المدين', $statement['debit_minor'] / 100]);
            $row(['إجمالي الدائن', $statement['credit_minor'] / 100]);
            $row(['رصيد الإغلاق', $statement['closing_balance_minor'] / 100]);
            $row(['القيد', 'تاريخ الترحيل', 'النوع', 'البيان', 'العداد', 'مدين', 'دائن', 'الرصيد المتحرك'], $header);
            foreach ($statement['entries'] as $entry) {
                $row([$entry['id'], $entry['posting_date'], $entry['type'], $entry['description'] ?? '',
                    $entry['meter_code'] ?? '', $entry['debit_minor'] / 100, $entry['credit_minor'] / 100,
                    $entry['running_balance_minor'] / 100]);
            }
            $writer->close();
            return $file;
        } catch (\Throwable $exception) {
            @unlink($file);
            throw $exception;
        }
    }
}

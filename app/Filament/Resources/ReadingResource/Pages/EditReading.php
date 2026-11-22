<?php

namespace App\Filament\Resources\ReadingResource\Pages;

use App\Filament\Resources\ReadingResource;
use App\Models\Meter;
use App\Models\Reading;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditReading extends EditRecord
{
    protected static string $resource = ReadingResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    /**
     * A measured boundary may not be silently edited in Filament.
     * Correcting a previous reading requires a dedicated, audited chain
     * adjustment, since the next meter observation uses its value/date.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $meter = Meter::query()->lockForUpdate()->findOrFail($record->meter_id);
            $reading = Reading::query()->lockForUpdate()->findOrFail($record->getKey());

            if ($reading->is_invoiced || $reading->invoice_id !== null) {
                throw ValidationException::withMessages([
                    'current_reading' => ['القراءة مرتبطة بفاتورة ولا يمكن تعديلها مباشرة.'],
                ]);
            }

            foreach (['period_start', 'period_end'] as $field) {
                if (isset($data[$field])
                    && Carbon::parse($data[$field])->toDateString()
                        !== Carbon::parse($reading->{$field})->toDateString()) {
                    throw ValidationException::withMessages([
                        $field => ['تُشتق فترة الاستهلاك من تواريخ الرصد ولا تُغيّر يدوياً.'],
                    ]);
                }
            }

            foreach (['reading_date', 'actual_reading_date'] as $field) {
                if (isset($data[$field])) {
                    $stored = $reading->{$field} ?? $reading->reading_date;
                    if (Carbon::parse($data[$field])->toDateString()
                        !== Carbon::parse($stored)->toDateString()) {
                        throw ValidationException::withMessages([
                            $field => ['تصحيح تاريخ الرصد يتطلب مراجعة فترة القياس وليس التعديل المباشر.'],
                        ]);
                    }
                }
            }

            if (array_key_exists('previous_reading', $data)
                && abs((float) $data['previous_reading'] - (float) $reading->previous_reading) > 0.00001) {
                throw ValidationException::withMessages([
                    'previous_reading' => ['قيمة القراءة السابقة مشتقة من العداد ولا تُعدّل مباشرة.'],
                ]);
            }

            $current = (float) ($data['current_reading'] ?? $reading->current_reading);
            if ($current < (float) $reading->previous_reading) {
                throw ValidationException::withMessages([
                    'current_reading' => ['قيمة القراءة الحالية أقل من السابقة.'],
                ]);
            }
            $changed = abs($current - (float) $reading->current_reading) > 0.00001;
            if ($changed) {
                $observed = Carbon::parse(
                    $reading->actual_reading_date ?? $reading->period_end ?? $reading->reading_date
                )->toDateString();
                $hasSuccessor = $meter->readings()->whereKeyNot($reading->id)
                    ->whereRaw('COALESCE(actual_reading_date, period_end, reading_date) > ?', [$observed])
                    ->exists();
                if ($hasSuccessor) {
                    throw ValidationException::withMessages([
                        'current_reading' => ['توجد قراءة لاحقة تعتمد على هذه القيمة؛ يلزم تصحيح متسلسل معتمد.'],
                    ]);
                }
            }

            unset($data['period_start'], $data['period_end'], $data['reading_date'],
                $data['actual_reading_date'], $data['previous_reading']);
            $reading->update($data);

            return $reading;
        });
    }
}

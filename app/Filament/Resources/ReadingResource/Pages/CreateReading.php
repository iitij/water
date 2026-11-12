<?php
namespace App\Filament\Resources\ReadingResource\Pages;
use App\Filament\Resources\ReadingResource;
use Filament\Resources\Pages\CreateRecord;
class CreateReading extends CreateRecord
{
    protected static string $resource = ReadingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $meter = \App\Models\Meter::findOrFail($data['meter_id']);
        if ($meter->removed_at !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'meter_id' => ['لا يمكن إنشاء قراءة جديدة لعداد منزوع. اختر العداد الحالي.'],
            ]);
        }

        return $data;
    }
}






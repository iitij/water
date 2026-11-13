<?php

namespace App\Filament\Resources\Meters\Pages;

use App\Filament\Resources\Meters\MeterResource;
use App\Services\SingleMeterService;
use Illuminate\Database\Eloquent\Model;
use Filament\Resources\Pages\EditRecord;

class EditMeter extends EditRecord
{
    protected static string $resource = MeterResource::class;

    /**
     * Replacement is a single transactional operation; never ask the user
     * to manually remove the old meter and then create an unrelated one.
     */
    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('replace_meter')
                ->label('استبدال العداد')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->record?->isCurrent() === true)
                ->modalHeading('استبدال العداد الحالي')
                ->modalDescription('يحتفظ النظام بالعداد القديم وقراءاته وفواتيره وأرصدته، ويمنع الاستبدال عند وجود قراءات غير مفوترة.')
                ->schema([
                    \Filament\Forms\Components\TextInput::make('new_unique_code')
                        ->label('رقم العداد الجديد')
                        ->required()
                        ->maxLength(50)
                        ->unique(table: \App\Models\Meter::class, column: 'unique_code'),
                    \Filament\Forms\Components\TextInput::make('new_path_number')
                        ->label('المسار الجديد')
                        ->integer()
                        ->minValue(0)
                        ->default(fn (): ?int => $this->record?->path_number),
                    \Filament\Forms\Components\TextInput::make('initial_reading')
                        ->label('القراءة الابتدائية للعداد الجديد')
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->required(),
                    \Filament\Forms\Components\DatePicker::make('removed_at')
                        ->label('تاريخ نزع العداد القديم')
                        ->default(now())
                        ->maxDate(now())
                        ->required(),
                    \Filament\Forms\Components\Textarea::make('reason')
                        ->label('سبب الاستبدال')
                        ->required()
                        ->minLength(3)
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $result = app(SingleMeterService::class)->replace(
                        $this->record,
                        [
                            'unique_code' => $data['new_unique_code'],
                            'path_number' => $data['new_path_number'] ?? null,
                            'initial_reading' => $data['initial_reading'] ?? 0,
                            'installed_at' => $data['removed_at'],
                        ],
                        (string) $data['removed_at'],
                        (string) $data['reason'],
                        \Illuminate\Support\Facades\Auth::id(),
                    );
                    \Filament\Notifications\Notification::make()
                        ->title('تم استبدال العداد وحفظ التاريخ المالي')
                        ->success()
                        ->send();
                    $this->redirect(MeterResource::getUrl('edit', [
                        'record' => $result['current_meter']->getKey(),
                    ]));
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(SingleMeterService::class)->update($record, $data);
    }
}

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReadingResource\Pages;
use App\Models\Reading;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ReadingResource extends Resource
{
    protected static ?string $model = Reading::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-eye';

    protected static string | \UnitEnum | null $navigationGroup = 'العمليات';

    protected static ?string $modelLabel = 'قراءة عداد';

    protected static ?string $pluralModelLabel = 'قراءات العدادات';

    protected static ?int $navigationSort = 1;

    public static function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema
    {
        return $form->schema([
            Forms\Components\Select::make('subscriber_id')
                ->label('المشترك')
                ->searchable()
                ->options(function () {
                    $subscribers = \App\Models\Subscriber::with('currentMeter')->where('status', 'active')->get();
                    $results = [];
                    foreach ($subscribers as $subscriber) {
                        $results[(string)$subscriber->id] = $subscriber->name;
                    }
                    return $results;
                })
                ->required()
                ->live()
                ->afterStateUpdated(function ($set, callable $get, $state) {
                    if (!$state) {
                        $set('meter_id', null);
                        $set('previous_reading', null);
                        return;
                    }

                    $subscriber = \App\Models\Subscriber::find($state);
                    if ($subscriber && ($meter = $subscriber->currentMeter)) {
                        $set('meter_id', $meter->id);
                        $set('previous_reading', $meter->getLastReadingValue());
                        $service = new \App\Services\ReadingService();
                        $period = $service->calculateReadingPeriod($meter->id);
                        $set('period_start', $period['period_start']);
                        $set('period_end', $period['period_end']);
                    } else {
                        $currentMeter = \App\Models\Meter::find($get('meter_id'));
                        if (!$currentMeter || $currentMeter->subscriber_id != $state) {
                            $set('meter_id', null);
                            $set('previous_reading', null);
                            $set('period_start', null);
                            $set('period_end', null);
                        }
                    }
                })
                ->afterStateHydrated(function ($set, $state, $record) {
                    if ($record && $record->meter) {
                        $set('subscriber_id', $record->meter->subscriber_id);
                    }
                })
                ->dehydrated(false)
                ->preload()
                ->columnSpanFull(),

            Forms\Components\Select::make('meter_id')
                ->label('العداد')
                ->relationship(
                    name: 'meter',
                    modifyQueryUsing: fn(\Illuminate\Database\Eloquent\Builder $query, $get) =>
                    $get('subscriber_id') ? $query->whereNull('removed_at')->where('subscriber_id', $get('subscriber_id')) : $query->whereNull('removed_at')
                )
                ->default(fn () => request()->integer('meter_id') ?: null)
                ->afterStateHydrated(function ($set, $state, $record) {
                    if ($record || ! $state) return;
                    $meter = \App\Models\Meter::query()->whereNull('removed_at')->find($state);
                    if (! $meter) return;
                    $set('subscriber_id', (string) $meter->subscriber_id);
                    $set('previous_reading', $meter->getLastReadingValue());
                    $period = app(\App\Services\ReadingService::class)->calculateReadingPeriod($meter->id);
                    $set('period_start', $period['period_start']);
                    $set('period_end', $period['period_end']);
                })
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->unique_code)
                ->required()
                ->searchable()
                ->live()
                ->afterStateUpdated(function ($set, callable $get, $state) {
                    if ($state) {
                        $meter = \App\Models\Meter::find($state);
                        if ($meter) {
                            $set('previous_reading', $meter->getLastReadingValue());
                            $expectedSubValue = (string) $meter->subscriber_id;
                            if ($get('subscriber_id') !== $expectedSubValue) {
                                $set('subscriber_id', $expectedSubValue);
                            }

                            // Calculate reading period
                            $service = new \App\Services\ReadingService();
                            $period = $service->calculateReadingPeriod($state);
                            if ($period['period_start']) {
                                $set('period_start', $period['period_start']);
                                $set('period_end', $period['period_end']);
                            } else {
                                $set('period_start', null);
                                $set('period_end', null);
                            }
                        }
                    } else {
                        $set('previous_reading', null);
                        $set('period_start', null);
                        $set('period_end', null);
                    }
                })
                ->preload()
                ->columnSpanFull(),

            \Filament\Schemas\Components\Grid::make(2)
                ->schema([
                    Forms\Components\DatePicker::make('period_start')
                        ->label('فترة القراءة (من تاريخ)')
                        ->readOnly()
                        ->dehydrated()
                        ->required(),

                    Forms\Components\DatePicker::make('period_end')
                        ->label('فترة القراءة (إلى تاريخ)')
                        ->readOnly()
                        ->dehydrated()
                        ->required()
                        ->unique(
                            table: \App\Models\Reading::class,
                            column: 'period_end',
                            modifyRuleUsing: function (\Illuminate\Validation\Rules\Unique $rule, $get) {
                                return $rule->where('meter_id', $get('meter_id'));
                            },
                            ignoreRecord: true
                        ),
                ]),

            Forms\Components\DatePicker::make('reading_date')
                ->label('تاريخ إدخال القراءة الفعلي')
                ->default(now())
                ->required()
                ->helperText('التاريخ الذي تمت فيه قراءة العداد فعلياً من قبل المحصل.'),

            Forms\Components\TextInput::make('current_reading')
                ->label('القراءة الحالية')
                ->numeric()
                ->rules([
                    fn($get, ?\Illuminate\Database\Eloquent\Model $record) => function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                        if (! $get('meter_id')) return;

                        $meter = \App\Models\Meter::find($get('meter_id'));
                        if ($meter) {
                            $lastReading = $meter->getLastReadingValue($record?->id);
                            if ($value < $lastReading) {
                                $fail("القراءة الحالية يجب أن تكون أكبر من أو تساوي القراءة السابقة ({$lastReading}).");
                            }
                        }
                    },
                ])
                ->required(),

            Forms\Components\TextInput::make('previous_reading')
                ->label('القراءة السابقة')
                ->numeric()
                ->readOnly()
                ->dehydrated()
                ->helperText('تُحسب وتظهر تلقائياً بمجرد اختيار العداد'),

        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordClasses(fn (Reading $record) => $record->is_invoiced ? 'reading-invoiced' : 'reading-uninvoiced')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('الرقم')
                    ->sortable(),

                Tables\Columns\TextColumn::make('meter.unique_code')
                    ->label('رقم العداد')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('meter.subscriber.name')
                    ->label('المشترك')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('reading_date')
                    ->label('التاريخ المعتمد')
                    ->date('Y-m-d')
                    ->sortable(),

                Tables\Columns\TextColumn::make('actual_reading_date')
                    ->label('التاريخ الفعلي')
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('previous_reading')
                    ->label('السابقة')
                    ->numeric(),

                Tables\Columns\TextColumn::make('current_reading')
                    ->label('الحالية')
                    ->numeric(),

                Tables\Columns\TextColumn::make('consumption_units')
                    ->label('الاستهلاك (وحدة)')
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\IconColumn::make('is_invoiced')
                    ->label('مفوترة')
                    ->boolean(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_invoiced')
                    ->label('حالة الفوترة')
                    ->trueLabel('مفوترة')
                    ->falseLabel('غير مفوترة'),
            ])
            ->actions([
                \Filament\Actions\EditAction::make()
                    ->hidden(fn (Reading $record): bool => $record->is_invoiced),
                \Filament\Actions\DeleteAction::make()
                    ->hidden(fn (Reading $record): bool => $record->is_invoiced),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\BulkAction::make('issue_invoices')
                        ->label('إصدار الفواتير')
                        ->icon('heroicon-o-document-currency-dollar')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('إصدار الفواتير للقراءات المحددة')
                        ->modalDescription('هل أنت متأكد من إصدار فواتير لجميع القراءات غير المفوترة المحددة؟')
                        ->modalSubmitActionLabel('إصدار الفواتير الآن')
                        ->modalCancelActionLabel('إلغاء')
                        ->modalIcon('heroicon-o-document-currency-dollar')
                        ->modalIconColor('success')
                        ->extraAttributes([
                            'x-show' => "getSelectedRecordsCount() && !document.querySelector('tr.reading-invoiced input[type=checkbox]:checked')",
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            if ($records->contains('is_invoiced', true)) {
                                \Filament\Notifications\Notification::make()
                                    ->title('لا يمكن إصدار الفواتير')
                                    ->body('توجد قراءات مفوترة مسبقاً ضمن القراءات المحددة. يجب أن تكون جميع القراءات المحددة غير مفوترة.')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            $invoiceService = new \App\Services\InvoiceService();
                            $recordsByMeter = $records->groupBy('meter_id');
                            $invoicesCreated = 0;
                            $errors = [];

                            foreach ($recordsByMeter as $meterId => $meterReadings) {
                                $firstReading = $meterReadings->first();
                                $meter = $firstReading->meter;
                                if (! $meter || ! $meter->subscriber) {
                                    continue;
                                }

                                try {
                                    $invoiceService->generateInvoice(
                                        subscriber: $meter->subscriber,
                                        meter: $meter,
                                        notes: null,
                                    );
                                    $invoicesCreated++;
                                } catch (\Throwable $e) {
                                    $errors[] = "العداد {$meter->unique_code}: " . $e->getMessage();
                                }
                            }

                            if ($invoicesCreated > 0) {
                                \Filament\Notifications\Notification::make()
                                    ->title('تم إصدار الفواتير بنجاح')
                                    ->body("تم إصدار {$invoicesCreated} فاتورة للقراءات المحددة بنجاح.")
                                    ->success()
                                    ->send();
                            }

                            if (! empty($errors)) {
                                \Filament\Notifications\Notification::make()
                                    ->title('تنبيه أثناء الإصدار')
                                    ->body(implode("\n", array_slice($errors, 0, 5)))
                                    ->warning()
                                    ->send();
                            }
                        }),

                    \Filament\Actions\DeleteBulkAction::make()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $deletedCount = 0;
                            $skippedCount = 0;
                            foreach ($records as $record) {
                                if ($record->is_invoiced) {
                                    $skippedCount++;
                                    continue;
                                }
                                $record->delete();
                                $deletedCount++;
                            }
                            if ($skippedCount > 0) {
                                \Filament\Notifications\Notification::make()
                                    ->warning()
                                    ->title('تنبيه')
                                    ->body("تم حذف {$deletedCount} قراءة غير مفوترة. تم تخطي {$skippedCount} قراءة لأنها مفوترة بالفعل.")
                                    ->send();
                            } else {
                                \Filament\Notifications\Notification::make()
                                    ->success()
                                    ->title('تم الحذف')
                                    ->body("تم حذف {$deletedCount} قراءة بنجاح.")
                                    ->send();
                            }
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReadings::route('/'),
        ];
    }
}

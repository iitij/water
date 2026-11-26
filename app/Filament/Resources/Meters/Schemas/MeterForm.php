<?php

namespace App\Filament\Resources\Meters\Schemas;

use Filament\Forms;
use Filament\Schemas\Schema;

class MeterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Schemas\Components\Section::make('بيانات العداد')
                    ->schema([
                        Forms\Components\TextInput::make('unique_code')
                            ->label('رقم العداد')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(50),
                        Forms\Components\TextInput::make('path_number')
                            ->label('رقم المسار')
                            ->numeric()
                            ->helperText('رقم المسار للعداد داخل المربع السكني لتسهيل البحث (اختياري)')
                            ->rules([
                                fn ($get, ?\Illuminate\Database\Eloquent\Model $record) => function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                                    if ($value !== null && $value !== '') {
                                        $subscriberId = $get('subscriber_id');
                                        if ($subscriberId) {
                                            $subscriber = \App\Models\Subscriber::find($subscriberId);
                                            if ($subscriber) {
                                                $query = \App\Models\Meter::where('path_number', $value)
                                                    ->whereHas('subscriber', function($q) use ($subscriber) {
                                                        $q->where('square_id', $subscriber->square_id);
                                                    });
                                                if ($record) {
                                                    $query->where('id', '!=', $record->id);
                                                }
                                                if ($query->exists()) {
                                                    $fail('رقم المسار مستخدم بالفعل لعداد آخر في نفس المربع السكني.');
                                                }
                                            }
                                        }
                                    }
                                },
                            ]),
                        Forms\Components\Select::make('subscriber_id')
                            ->label('المشترك')
                            ->relationship('subscriber', 'name')
                            ->helperText('يمكن ربط عداد واحد فقط بكل مشترك؛ لا تُحفظ عدادات تاريخية.')
                            ->rules([
                                fn ($get, ?\Illuminate\Database\Eloquent\Model $record) =>
                                    function (string $attribute, $value, \Closure $fail) use ($record): void {
                                        if (! $value) {
                                            return;
                                        }
                                        $existing = \App\Models\Meter::withTrashed()
                                            ->where('subscriber_id', (int) $value)
                                            ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
                                            ->exists();
                                        if ($existing) {
                                            $fail('هذا المشترك مرتبط بعداد بالفعل. لا يمكن إضافة عداد ثانٍ.');
                                        }
                                    },
                            ])
                            ->required()
                            ->searchable()
                            ->live(),
                        Forms\Components\DatePicker::make('installed_at')
                            ->label('تاريخ تركيب العداد')
                            ->maxDate(today())
                            ->disabled(fn (?\App\Models\Meter $record) => $record?->readings()->withTrashed()->exists() ?? false),
                        Forms\Components\TextInput::make('initial_reading')
                            ->label('القراءة الابتدائية')
                            ->numeric()->minValue(0)->maxValue(9999999999.99)->step(0.01)
                            ->rule('decimal:0,2')->default(0)->required()
                            ->disabled(fn (?\App\Models\Meter $record) => $record?->readings()->withTrashed()->exists() ?? false),
                        Forms\Components\Select::make('status')
                            ->label('حالة العداد')
                            ->options([
                                'active' => 'نشط',
                                'inactive' => 'غير نشط',
                            ])
                            ->default('active')
                            ->required(),
                    ])->columns(2),
            ]);
    }
}

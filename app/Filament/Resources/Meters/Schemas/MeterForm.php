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
                            ->required()
                            ->searchable()
                            ->live(),
                        Forms\Components\TextInput::make('tenant_name')
                            ->label('اسم المستأجر')
                            ->maxLength(255)
                            ->helperText('اختياري: إذا كان العداد يخص شقة مستأجر معين')
                            ->visible(function ($get, ?\Illuminate\Database\Eloquent\Model $record) {
                                $subscriberId = $get('subscriber_id');
                                if (!$subscriberId) return false;
                                $meterCount = \App\Models\Meter::where('subscriber_id', $subscriberId)->count();
                                return $record ? $meterCount > 1 : $meterCount > 0;
                            }),
                        \Ysfkaya\FilamentPhoneInput\Forms\PhoneInput::make('tenant_phone')
                            ->label('رقم هاتف المستأجر (إن وجد)')
                            ->defaultCountry('YE')
                            ->initialCountry('ye')
                            ->helperText('اختياري')
                            ->visible(function ($get, ?\Illuminate\Database\Eloquent\Model $record) {
                                $subscriberId = $get('subscriber_id');
                                if (!$subscriberId) return false;
                                $meterCount = \App\Models\Meter::where('subscriber_id', $subscriberId)->count();
                                return $record ? $meterCount > 1 : $meterCount > 0;
                            }),
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

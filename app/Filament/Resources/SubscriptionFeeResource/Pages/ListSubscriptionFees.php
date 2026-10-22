<?php

namespace App\Filament\Resources\SubscriptionFeeResource\Pages;

use App\Filament\Resources\SubscriptionFeeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptionFees extends ListRecords
{
    protected static string $resource = SubscriptionFeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('add_fee')
                ->label('إضافة رسوم اشتراك')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->form([
                    \Filament\Forms\Components\Select::make('subscriber_id')
                        ->label('المشترك')
                        ->options(\App\Models\Subscriber::where('status', 'active')->pluck('name', 'id'))
                        ->searchable()
                        ->reactive()
                        ->required(),
                    \Filament\Forms\Components\Select::make('meter_id')
                        ->label('العداد')
                        ->options(function (callable $get) {
                            $subId = $get('subscriber_id');
                            if (!$subId) return [];
                            return \App\Models\Meter::where('subscriber_id', $subId)->pluck('unique_code', 'id');
                        })
                        ->searchable(),
                    \Filament\Forms\Components\TextInput::make('amount')
                        ->label('قيمة رسوم الاشتراك (للعداد)')
                        ->numeric()
                        ->required()
                        ->prefix('ريال'),
                ])
                ->action(function (array $data) {
                    \App\Models\SubscriptionFee::create([
                        'subscriber_id' => $data['subscriber_id'],
                        'meter_id' => $data['meter_id'] ?? null,
                        'amount' => $data['amount'],
                        'status' => 'uninvoiced',
                    ]);
                })
                ->successNotificationTitle('تمت إضافة رسوم الاشتراك بنجاح'),
        ];
    }
}

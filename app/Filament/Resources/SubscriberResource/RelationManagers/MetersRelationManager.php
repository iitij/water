<?php

namespace App\Filament\Resources\SubscriberResource\RelationManagers;

use App\Models\Meter;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

class MetersRelationManager extends RelationManager
{
    protected static string $relationship = 'meters';

    protected static ?string $title = 'العداد الحالي وسجل العدادات السابقة';

    public function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('unique_code')
                ->label('رقم العداد')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(50),
                
            Forms\Components\TextInput::make('tenant_name')
                ->label('اسم المستأجر')
                ->maxLength(255)
                ->helperText('اختياري: إذا كان العداد يخص شقة مستأجر معين'),
                
            \Ysfkaya\FilamentPhoneInput\Forms\PhoneInput::make('tenant_phone')
                ->label('رقم هاتف المستأجر (إن وجد)')
                ->defaultCountry('YE')
                ->initialCountry('ye')
                ->helperText('اختياري'),

            Forms\Components\Select::make('status')
                ->label('الحالة')
                ->options([
                    'active' => 'نشط',
                    'inactive' => 'غير نشط',
                ])
                ->default('active')
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('unique_code')
                    ->label('رقم العداد')
                    ->searchable(),

                Tables\Columns\TextColumn::make('tenant_name')
                    ->label('المستأجر')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'نشط',
                        'inactive' => 'غير نشط',
                        default => $state,
                    })
                    ->colors([
                        'success' => 'active',
                        'danger' => 'inactive',
                    ]),

                Tables\Columns\TextColumn::make('readings_count')
                    ->label('القراءات')
                    ->counts('readings'),
            ])
            ->headerActions([])
            ->actions([
                \Filament\Actions\Action::make('record_reading')
                    ->label('تسجيل قراءة')
                    ->icon('heroicon-o-eye')
                    ->color('primary')
                    ->form([
                        Forms\Components\DatePicker::make('reading_date')
                            ->label('تاريخ القراءة')
                            ->default(now())
                            ->required(),

                        Forms\Components\TextInput::make('current_reading')
                            ->label('القراءة الحالية')
                            ->numeric()
                            ->required(),

                        Forms\Components\Placeholder::make('auto_invoice_warning')
                            ->label('')
                            ->content(function (\App\Models\Meter $record) {
                                $subscriber = $record->subscriber;
                                if (!$subscriber) return '';
                                
                                $hasSub = \App\Models\SubscriptionFee::where('subscriber_id', $subscriber->id)->where('status', 'uninvoiced')->exists();
                                $hasTanker = \App\Models\ServiceRequest::where('subscriber_id', $subscriber->id)->where('status', 'completed')->exists();
                                
                                $msg = "سيتم اصدار الفاتورة تلقائياً يرجى الاطلاع على الاتي قبل النقر على زر الاضافة:\n";
                                if ($hasSub && $hasTanker) {
                                    $msg .= "هذا العميل لديه رسوم اشتراك ورسوم وايت غير مفوترة.";
                                } elseif ($hasSub) {
                                    $msg .= "هذا العميل لديه رسوم اشتراك غير مفوترة.";
                                } elseif ($hasTanker) {
                                    $msg .= "هذا العميل لديه رسوم وايت غير مفوترة.";
                                }
                                return new \Illuminate\Support\HtmlString('<div style="padding: 1rem; margin-bottom: 1rem; font-size: 0.875rem; color: #92400e; background-color: #fffbeb; border-radius: 0.5rem;" role="alert"><span style="font-weight: 500;">' . nl2br(e($msg)) . '</span></div>');
                            })
                            ->visible(function (\App\Models\Meter $record) {
                                if (\App\Models\Setting::get('auto_issue_invoice_on_reading', '0') != '1') return false;
                                $subscriber = $record->subscriber;
                                if (!$subscriber) return false;
                                
                                $hasSub = \App\Models\SubscriptionFee::where('subscriber_id', $subscriber->id)->where('status', 'uninvoiced')->exists();
                                $hasTanker = \App\Models\ServiceRequest::where('subscriber_id', $subscriber->id)->where('status', 'completed')->exists();
                                return $hasSub || $hasTanker;
                            })
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('has_subscription_fee')
                            ->label('إضافة مبالغ الاشتراك')
                            ->visible(function (\App\Models\Meter $record) {
                                if (\App\Models\Setting::get('auto_issue_invoice_on_reading', '0') != '1') return false;
                                $subscriber = $record->subscriber;
                                if (!$subscriber) return false;
                                return \App\Models\SubscriptionFee::where('subscriber_id', $subscriber->id)->where('status', 'uninvoiced')->exists();
                            })
                            ->reactive()
                            ->afterStateUpdated(function($state, callable $set, \App\Models\Meter $record) {
                                if (!$state) {
                                    $set('subscription_installment', 0);
                                } else {
                                    if ($record->subscriber) {
                                        $set('subscription_installment', \App\Models\SubscriptionFee::where('subscriber_id', $record->subscriber->id)->where('status', 'uninvoiced')->sum('amount'));
                                    }
                                }
                            }),

                        Forms\Components\TextInput::make('subscription_installment')
                            ->label('مبلغ الاشتراك')
                            ->numeric()
                            ->default(0)
                            ->prefix('ريال')
                            ->visible(fn(callable $get) => $get('has_subscription_fee')),

                        Forms\Components\Toggle::make('has_tanker_fee')
                            ->label('إضافة رسوم الوايت')
                            ->visible(function (\App\Models\Meter $record) {
                                if (\App\Models\Setting::get('auto_issue_invoice_on_reading', '0') != '1') return false;
                                $subscriber = $record->subscriber;
                                if (!$subscriber) return false;
                                return \App\Models\ServiceRequest::where('subscriber_id', $subscriber->id)->where('status', 'completed')->exists();
                            })
                            ->reactive()
                            ->afterStateUpdated(function($state, callable $set, \App\Models\Meter $record) {
                                if (!$state) {
                                    $set('tanker_installment', 0);
                                } else {
                                    if ($record->subscriber) {
                                        $set('tanker_installment', \App\Models\ServiceRequest::where('subscriber_id', $record->subscriber->id)->where('status', 'completed')->sum('amount'));
                                    }
                                }
                            }),

                        Forms\Components\TextInput::make('tanker_installment')
                            ->label('مبلغ الوايت')
                            ->numeric()
                            ->default(0)
                            ->prefix('ريال')
                            ->visible(fn(callable $get) => $get('has_tanker_fee')),
                    ])
                    ->action(function (\App\Models\Meter $record, array $data): void {
                        $reading = $record->readings()->create([
                            'reading_date' => $data['reading_date'],
                            'current_reading' => $data['current_reading'],
                            'created_by' => \Illuminate\Support\Facades\Auth::id(),
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->title('تم تسجيل القراءة بنجاح')
                            ->success()
                            ->send();

                        if (\App\Models\Setting::get('auto_issue_invoice_on_reading', '0') == '1') {
                            try {
                                $invoiceService = new \App\Services\InvoiceService();
                                
                                $subscriptionInstallment = (isset($data['has_subscription_fee']) && $data['has_subscription_fee']) ? (float) ($data['subscription_installment'] ?? 0) : null;
                                $tankerInstallment = (isset($data['has_tanker_fee']) && $data['has_tanker_fee']) ? (float) ($data['tanker_installment'] ?? 0) : null;
                                
                                $invoiceService->generateInvoice(
                                    subscriber: $record->subscriber,
                                    meter: $record,
                                    subscriptionInstallment: $subscriptionInstallment,
                                    notes: null,
                                    tankerInstallment: $tankerInstallment
                                );
                                \Filament\Notifications\Notification::make()
                                    ->title('تم إصدار الفاتورة تلقائياً')
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {
                                \Filament\Notifications\Notification::make()
                                    ->title('حدث خطأ أثناء إصدار الفاتورة تلقائياً')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }
                    }),

                \Filament\Actions\EditAction::make(),
            ]);
    }
}






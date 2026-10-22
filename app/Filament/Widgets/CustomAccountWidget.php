<?php

namespace App\Filament\Widgets;

use Filament\Widgets\AccountWidget;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use App\Models\Reading;
use App\Filament\Resources\ReadingResource;
use App\Models\Subscriber;
use App\Filament\Resources\SubscriberResource;

class CustomAccountWidget extends AccountWidget implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected int | string | array $columnSpan = 'full';

    protected string $view = 'filament.widgets.custom-account-widget';

    public function createReadingAction(): Action
    {
        return CreateAction::make('createReading')
            ->model(Reading::class)
            ->label('إضافة قراءة')
            ->icon('heroicon-m-plus')
            ->color('gray')
            ->form(function ($form) {
                return ReadingResource::form($form);
            })
            ->mutateFormDataUsing(function (array $data): array {
                $data['created_by'] = \Illuminate\Support\Facades\Auth::id();
                return $data;
            })
            ->after(function (\App\Models\Reading $record, array $data) {
                if (\App\Models\Setting::get('auto_issue_invoice_on_reading', '0') == '1') {
                    try {
                        $invoiceService = new \App\Services\InvoiceService();
                        
                        $subscriptionInstallment = (isset($data['has_subscription_fee']) && $data['has_subscription_fee']) ? (float) ($data['subscription_installment'] ?? 0) : null;
                        $tankerInstallment = (isset($data['has_tanker_fee']) && $data['has_tanker_fee']) ? (float) ($data['tanker_installment'] ?? 0) : null;
                        
                        $invoiceService->generateInvoice(
                            subscriber: $record->meter->subscriber,
                            meter: $record->meter,
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
            });
    }

    public function createSubscriberAction(): Action
    {
        return CreateAction::make('createSubscriber')
            ->model(Subscriber::class)
            ->label('إضافة مشترك')
            ->icon('heroicon-m-user-plus')
            ->color('gray')
            ->modalWidth('2xl')
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('saveAndAddMeter', arguments: ['addMeter' => true])
                    ->label('حفظ واضافة عداد')
                    ->color('info'),
            ])
            ->after(function (array $arguments, Subscriber $record, \Livewire\Component $livewire) {
                if ($arguments['addMeter'] ?? false) {
                    $livewire->replaceMountedAction('createMeter', ['subscriber_id' => $record->id]);
                }
            })
            ->form(function ($form) {
                return $form->schema([
                    \Filament\Forms\Components\TextInput::make('name')
                        ->label('اسم المشترك')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),

                    \Ysfkaya\FilamentPhoneInput\Forms\PhoneInput::make('phone')
                        ->label('رقم الهاتف')
                        ->defaultCountry('YE')
                        ->initialCountry('ye')
                        ->columnSpan(1),

                    \Filament\Forms\Components\Select::make('square_id')
                        ->label('المربع السكني')
                        ->relationship('square', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->columnSpan(1),

                    \Filament\Forms\Components\Select::make('status')
                        ->label('الحالة')
                        ->options([
                            'active' => 'نشط',
                            'inactive' => 'غير نشط',
                            'suspended' => 'موقوف',
                        ])
                        ->default('active')
                        ->required()
                        ->columnSpan(1),

                    \Filament\Forms\Components\DatePicker::make('subscription_start_date')
                        ->label('تاريخ بداية الاشتراك')
                        ->default(now())
                        ->columnSpan(1),

                    \Filament\Forms\Components\Textarea::make('address')
                        ->label('العنوان')
                        ->columnSpan(2)
                        ->rows(2),
                ])->columns(2);
            });
    }

    public function createMeterAction(): Action
    {
        return CreateAction::make('createMeter')
            ->model(\App\Models\Meter::class)
            ->label('إضافة عداد')
            ->modalHeading('إضافة عداد')
            ->modalWidth('2xl')
            ->createAnother(false)
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('saveAndAddSubscriptionFee', arguments: ['addSubscriptionFee' => true])
                    ->label('حفظ واضافة رسوم اشتراك')
                    ->color('info'),
            ])
            ->after(function (array $arguments, \App\Models\Meter $record, \Livewire\Component $livewire) {
                if ($arguments['addSubscriptionFee'] ?? false) {
                    $livewire->replaceMountedAction('createSubscriptionFee', [
                        'subscriber_id' => $record->subscriber_id,
                        'meter_id' => $record->id,
                    ]);
                }
            })
            ->mountUsing(function ($form, array $arguments) {
                $form->fill([
                    'subscriber_id' => $arguments['subscriber_id'] ?? null,
                    'status' => 'active',
                ]);
            })
            ->form(function ($form) {
                return $form->schema([
                    \Filament\Forms\Components\TextInput::make('unique_code')
                        ->label('رقم العداد')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(50)
                        ->columnSpan(1),

                    \Filament\Forms\Components\Select::make('subscriber_id')
                        ->label('المشترك')
                        ->relationship('subscriber', 'name')
                        ->required()
                        ->searchable()
                        ->columnSpan(1),

                    \Filament\Forms\Components\TextInput::make('tenant_name')
                        ->label('اسم المستأجر')
                        ->maxLength(255)
                        ->helperText('اختياري: إذا كان العداد يخص شقة مستأجر معين')
                        ->columnSpan(1),

                    \Ysfkaya\FilamentPhoneInput\Forms\PhoneInput::make('tenant_phone')
                        ->label('رقم هاتف المستأجر (إن وجد)')
                        ->defaultCountry('YE')
                        ->initialCountry('ye')
                        ->helperText('اختياري')
                        ->columnSpan(1),

                    \Filament\Forms\Components\Select::make('status')
                        ->label('حالة العداد')
                        ->options([
                            'active' => 'نشط',
                            'inactive' => 'غير نشط',
                        ])
                        ->default('active')
                        ->required()
                        ->columnSpan(2),
                ])->columns(2);
            });
    }
    public function createSubscriptionFeeAction(): Action
    {
        return CreateAction::make('createSubscriptionFee')
            ->model(\App\Models\SubscriptionFee::class)
            ->label('إضافة رسوم اشتراك')
            ->modalHeading('إضافة رسوم اشتراك للعداد الجديد')
            ->modalWidth('2xl')
            ->createAnother(false)
            ->mountUsing(function ($form, array $arguments) {
                $form->fill([
                    'subscriber_id' => $arguments['subscriber_id'] ?? null,
                    'meter_id' => $arguments['meter_id'] ?? null,
                    'status' => 'uninvoiced',
                ]);
            })
            ->form(function ($form) {
                return \App\Filament\Resources\SubscriptionFeeResource::form($form);
            });
    }

    public function createPaymentAction(): Action
    {
        return CreateAction::make('createPayment')
            ->model(\App\Models\Payment::class)
            ->label('إضافة سند قبض')
            ->icon('heroicon-m-banknotes')
            ->color('gray')
            ->modalWidth('2xl')
            ->form(function ($form) {
                return $form->schema([
                    \Filament\Forms\Components\Select::make('subscriber_id')
                        ->label('المشترك')
                        ->relationship('subscriber', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function (string $state, $set, $get) {
                            if ($state) {
                                $latestUnpaidInvoice = \App\Models\Invoice::where('subscriber_id', $state)
                                    ->where('status', '!=', 'paid')
                                    ->orderBy('id', 'desc')
                                    ->first();
                                if ($latestUnpaidInvoice) {
                                    $set('invoice_id', $latestUnpaidInvoice->id);
                                    $due = $latestUnpaidInvoice->total_amount + $latestUnpaidInvoice->previous_balance - $latestUnpaidInvoice->amount_paid;
                                    $set('invoice_amount', $due);
                                    $set('amount', $due);
                                } else {
                                    $set('invoice_id', null);
                                    $set('invoice_amount', null);
                                }
                            } else {
                                $set('invoice_id', null);
                                $set('invoice_amount', null);
                            }
                        })
                        ->columnSpan(1),

                    \Filament\Forms\Components\Select::make('invoice_id')
                        ->label('الفاتورة (اختياري)')
                        ->relationship(
                            'invoice', 
                            'bill_number',
                            fn (\Illuminate\Database\Eloquent\Builder $query, $get) => 
                                $query->where('subscriber_id', $get('subscriber_id'))
                                      ->where('status', '!=', 'paid')
                        )
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function ($state, $set) {
                            if ($state) {
                                $inv = \App\Models\Invoice::find($state);
                                if ($inv) {
                                    $due = $inv->total_amount + $inv->previous_balance - $inv->amount_paid;
                                    $set('invoice_amount', $due);
                                    $set('amount', $due);
                                }
                            } else {
                                $set('invoice_amount', null);
                            }
                        })
                        ->columnSpan(1),

                    \Filament\Forms\Components\TextInput::make('invoice_amount')
                        ->label('المبلغ المستحق')
                        ->disabled()
                        ->dehydrated(false)
                        ->numeric()
                        ->prefix('ريال')
                        ->columnSpan(1),

                    \Filament\Forms\Components\TextInput::make('receipt_number')
                        ->label('رقم سند القبض')
                        ->default(fn () => \App\Models\Payment::generateReceiptNumber())
                        ->disabled()
                        ->dehydrated()
                        ->columnSpan(1),

                    \Filament\Forms\Components\TextInput::make('amount')
                        ->label('المبلغ')
                        ->numeric()
                        ->required()
                        ->prefix('ريال')
                        ->columnSpan(1),

                    \Filament\Forms\Components\DatePicker::make('payment_date')
                        ->label('تاريخ الدفع')
                        ->default(now())
                        ->required()
                        ->columnSpan(1),

                    \Filament\Forms\Components\Select::make('payment_method')
                        ->label('طريقة الدفع')
                        ->options([
                            'cash' => 'نقدي',
                            'bank_transfer' => 'تحويل بنكي',
                            'check' => 'شيك',
                        ])
                        ->default('cash')
                        ->required()
                        ->columnSpan(1),

                    \Filament\Forms\Components\TextInput::make('reason')
                        ->label('وذلك مقابل')
                        ->columnSpan(2)
                        ->nullable(),

                    \Filament\Forms\Components\Textarea::make('notes')
                        ->label('ملاحظات')
                        ->columnSpan(2)
                        ->rows(2),
                ])->columns(2);
            });
    }
}

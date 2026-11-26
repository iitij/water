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
                        
                        $invoiceService->generateInvoice(
                            subscriber: $record->meter->subscriber,
                            meter: $record->meter,
                            notes: null,
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
                        ->getOptionLabelFromRecordUsing(fn (\App\Models\Square $record): string => $record->display_name)
                        ->required()
                        ->searchable(['name', 'code'])
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
            ->using(fn (array $data) => app(\App\Services\SingleMeterService::class)->create($data))

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

    public function createPaymentAction(): Action
    {
        return CreateAction::make('createPayment')
            ->model(\App\Models\Payment::class)
            ->label('إضافة سند قبض')
            ->icon('heroicon-m-banknotes')
            ->color('gray')
            ->modalWidth('2xl')
            ->createAnother(false)
            ->authorize(fn (): bool => $this->canIssueQuickReceipt())
            ->using(function (array $data): \App\Models\Payment {
                // Recheck on submission, not just when the dashboard button is rendered.
                abort_unless($this->canIssueQuickReceipt(), 403);
                $actor = filament()->auth()->user();
                \Illuminate\Support\Facades\Gate::forUser($actor)->authorize('create', \App\Models\Payment::class);

                // The ordinary receipt path is cash only. A cheque or bank transfer
                // must be submitted and independently cleared as a noncash claim.
                $validated = \Illuminate\Support\Facades\Validator::make($data, [
                    'subscriber_id' => ['required', 'integer', 'exists:subscribers,id'],
                    'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
                    'amount' => ['required', 'regex:/^\\d{1,10}(?:\\.\\d{1,2})?$/D', 'numeric', 'min:0.01', 'max:9999999999.99'],
                    'payment_date' => ['required', 'date_format:Y-m-d'],
                    'payment_method' => ['required', 'in:cash'],
                    'reason' => ['nullable', 'string', 'max:255'],
                    'notes' => ['nullable', 'string'],
                ])->validate();

                // Only the service generates the final number and writes the receipt,
                // allocation revision, ledger and collector session link atomically.
                // Do not accept a receipt number, actor, meter or session from the form.
                return app(\App\Services\PaymentService::class)->collectPayment(
                    subscriber: Subscriber::findOrFail($validated['subscriber_id']),
                    amount: $validated['amount'],
                    invoiceId: isset($validated['invoice_id']) ? (int) $validated['invoice_id'] : null,
                    paymentMethod: $validated['payment_method'],
                    notes: $validated['notes'] ?? null,
                    reason: $validated['reason'] ?? null,
                    userId: $actor->id,
                    paymentDate: $validated['payment_date'],
                );
            })
            ->form(function ($form) {
                return $form->schema([
                    \Filament\Forms\Components\Select::make('subscriber_id')
                        ->label('المشترك')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Subscriber::query()
                            ->where('name', 'like', '%'.$search.'%')->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Subscriber::find($value)?->name)
                        ->live()
                        ->afterStateUpdated(function ($state, $set) {
                            $set('invoice_id', null);
                            $set('invoice_amount', null);
                            $set('amount', null);

                            if ($state) {
                                $invoice = \App\Models\Invoice::collectibleForSubscriber((int) $state)
                                    ->orderByDesc('id')->first();
                                if ($invoice) {
                                    $set('invoice_id', $invoice->id);
                                    $set('invoice_amount', $invoice->getCollectibleAmount());
                                    $set('amount', $invoice->getCollectibleAmount());
                                }
                            }
                        })
                        ->columnSpan(1),

                    \Filament\Forms\Components\Select::make('invoice_id')
                        ->label('الفاتورة (اختياري)')
                        ->options(fn ($get): array => \App\Models\Invoice::collectibleForSubscriber(
                            $get('subscriber_id') ? (int) $get('subscriber_id') : null
                        )->orderByDesc('id')->pluck('bill_number', 'id')->all())
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function ($state, $set, $get) {
                            $set('invoice_amount', null);
                            $set('amount', null);

                            if ($state) {
                                $invoice = \App\Models\Invoice::collectibleForSubscriber(
                                    $get('subscriber_id') ? (int) $get('subscriber_id') : null
                                )->find($state);
                                if ($invoice) {
                                    $set('invoice_amount', $invoice->getCollectibleAmount());
                                    $set('amount', $invoice->getCollectibleAmount());
                                }
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
                        ->placeholder('يُحدد عند الحفظ')
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpan(1),

                    \Filament\Forms\Components\TextInput::make('amount')
                        ->label('المبلغ')
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->maxValue(9999999999.99)
                        ->rules(['regex:/^\\d{1,10}(?:\\.\\d{1,2})?$/D'])
                        ->prefix('ريال')
                        ->columnSpan(1),

                    \Filament\Forms\Components\DatePicker::make('payment_date')
                        ->label('تاريخ الدفع')
                        ->default(now())
                        ->required()
                        ->columnSpan(1),

                    \Filament\Forms\Components\Select::make('payment_method')
                        ->label('طريقة الدفع')
                        ->options(['cash' => 'نقدي'])
                        ->helperText('الشيكات والتحويلات البنكية تتطلب طلب تحصيل غير نقدي وإثبات وصول الأموال قبل إصدار السند.')
                        ->default('cash')
                        ->required()
                        ->columnSpan(1),

                    \Filament\Forms\Components\TextInput::make('reason')
                        ->label('وذلك مقابل')
                        ->maxLength(255)
                        ->columnSpan(2)
                        ->nullable(),

                    \Filament\Forms\Components\Textarea::make('notes')
                        ->label('ملاحظات')
                        ->columnSpan(2)
                        ->rows(2),
                ])->columns(2);
            });
    }

    private function canIssueQuickReceipt(): bool
    {
        $actor = filament()->auth()->user();

        return $actor !== null
            && in_array($actor->role, ['admin', 'collector'], true)
            && $actor->can('create', \App\Models\Payment::class);
    }
}

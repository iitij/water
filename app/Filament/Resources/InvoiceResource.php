<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvoiceResource\Pages;
use App\Models\Invoice;
use App\Models\Subscriber;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\Enums\Unit;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static string | \UnitEnum | null $navigationGroup = 'العمليات';

    protected static ?string $modelLabel = 'فاتورة';

    protected static ?string $pluralModelLabel = 'الفواتير';

    protected static ?int $navigationSort = 2;

    public static function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema
    {
        return $form->schema([
            \Filament\Schemas\Components\Section::make('بيانات الفاتورة')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('subscriber_id')
                        ->disabled(fn (?Invoice $record) => $record?->isFinanciallyLocked() ?? false)
                        ->label('المشترك')
                        ->relationship('subscriber', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function (\Filament\Schemas\Components\Utilities\Set $set, ?string $state) {
                            if (!$state) return;

                            $subscriber = Subscriber::find($state);
                            if (!$subscriber) return;

                            // Auto-fill outstanding balance
                            $set('previous_balance', $subscriber->getAccountDebt());

                        }),

                    Forms\Components\TextInput::make('bill_number')
                        ->label('رقم الفاتورة')
                        ->default(fn() => Invoice::generateBillNumber())
                        ->disabled()
                        ->dehydrated(fn (?Invoice $record) => $record === null),

                    Forms\Components\DatePicker::make('bill_date')
                        ->disabled(fn (?Invoice $record) => $record?->isFinanciallyLocked() ?? false)
                        ->label('تاريخ الفاتورة')
                        ->default(now())
                        ->required(),

                    Forms\Components\DatePicker::make('billing_period_start')
                        ->disabled(fn (?Invoice $record) => $record?->isFinanciallyLocked() ?? false)
                        ->label('بداية فترة استهلاك المياه')
                        ->readOnly(fn (?Invoice $record) => $record !== null && $record->readings()->exists())
                        ->helperText('فترة الاستهلاك مستقلة عن تاريخ إصدار الفاتورة.'),

                    Forms\Components\DatePicker::make('billing_period_end')
                        ->disabled(fn (?Invoice $record) => $record?->isFinanciallyLocked() ?? false)
                        ->label('نهاية فترة استهلاك المياه')
                        ->readOnly(fn (?Invoice $record) => $record !== null && $record->readings()->exists())
                        ->rules(['after_or_equal:billing_period_start'])
                        ->helperText('يجب ألا تسبق بداية الفترة.'),

                    Forms\Components\Select::make('status')
                        ->label('الحالة')
                        ->options([
                            'draft' => 'مسودة',
                            'issued' => 'صادرة',
                            'partially_paid' => 'مسددة جزئياً',
                            'paid' => 'مسددة',
                            'overdue' => 'متأخرة',
                        'cancelled' => 'ملغاة',
                        ])
                        ->default('issued')
                        ->disabled()
                        ->dehydrated(false),
                ]),

            \Filament\Schemas\Components\Section::make('تفاصيل المبالغ')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('consumption_fee')
                        ->disabled(fn (?Invoice $record) => $record?->isFinanciallyLocked() ?? false)
                        ->label('قيمة الاستهلاك')
                        ->numeric()
                        ->default(0)
                        ->prefix('ريال'),

                    Forms\Components\TextInput::make('previous_balance')
                        ->disabled(fn (?Invoice $record) => $record?->isFinanciallyLocked() ?? false)
                        ->label('المتأخرات السابقة')
                        ->numeric()
                        ->default(0)
                        ->prefix('ريال'),


                    Forms\Components\TextInput::make('credit_applied')
                        ->disabled(fn (?Invoice $record) => $record?->isFinanciallyLocked() ?? false)
                        ->label('خصم رصيد دائن')
                        ->numeric()
                        ->default(0)
                        ->prefix('ريال'),

                    Forms\Components\TextInput::make('total_amount')
                        ->disabled(fn (?Invoice $record) => $record?->isFinanciallyLocked() ?? false)
                        ->label('الإجمالي')
                        ->numeric()
                        ->default(0)
                        ->prefix('ريال'),

                    Forms\Components\TextInput::make('amount_paid')
                        ->label('المسدد')
                        ->numeric()
                        ->default(0)
                        ->prefix('ريال')
                        ->disabled()
                        ->dehydrated(false),
                ]),

            \Filament\Schemas\Components\Section::make('ملاحظات')
                ->schema([
                    Forms\Components\Textarea::make('notes')
                        ->label('ملاحظات')
                        ->rows(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('bill_number')
                    ->label('رقم الفاتورة')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('beneficiary_name')
                    ->label('المشترك')
                    ->state(fn (\App\Models\Invoice $record) => $record->subscriber->name ?? '-')
                    ->searchable(query: function (\Illuminate\Database\Eloquent\Builder $query, string $search): \Illuminate\Database\Eloquent\Builder {
                        return $query->whereHas('subscriber', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%");
                        });
                    })
                    ->sortable(query: function (\Illuminate\Database\Eloquent\Builder $query, string $direction): \Illuminate\Database\Eloquent\Builder {
                        return $query->orderBy(
                            \App\Models\Subscriber::select('name')->whereColumn('subscribers.id', 'invoices.subscriber_id'),
                            $direction
                        );
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('bill_date')
                    ->label('التاريخ')
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('billing_period_start')
                    ->label('بداية الفترة')
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('billing_period_end')
                    ->label('نهاية الفترة')
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('consumption_fee')
                    ->label('الاستهلاك')
                    ->numeric(2)->suffix(' ريال')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('previous_balance')
                    ->label('متأخرات سابقة')
                    ->numeric(2)->suffix(' ريال')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('قيمة الفاتورة')
                    ->numeric(2)->suffix(' ريال')
                    ->sortable()
                    ->weight('bold')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_due')
                    ->label('إجمالي المطلوب')
                    ->state(fn($record) => $record->total_amount + $record->previous_balance)
                    ->numeric(2)->suffix(' ريال')
                    ->sortable()
                    ->color('danger')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('amount_paid')
                    ->label('المسدد من الفاتورة')
                    ->numeric(2)->suffix(' ريال')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('remaining_amount')
                    ->label('المتبقي')
                    ->state(function (\App\Models\Invoice $record) {
                        if ($record->isLatestInvoice()) {
                            $trueRemaining = $record->getTrueRemainingAmount();
                            return $trueRemaining > 0 ? $trueRemaining : 0;
                        }
                        return null;
                    })
                    ->formatStateUsing(fn($state) => $state !== null ? number_format($state, 2) . ' ريال' : '-')
                    ->color(fn($state) => $state > 0 ? 'danger' : 'success')
                    ->weight('bold')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'draft' => 'مسودة',
                        'issued' => 'صادرة',
                        'partially_paid' => 'مسددة جزئياً',
                        'paid' => 'مسددة',
                        'overdue' => 'متأخرة',
                        'cancelled' => 'ملغاة',
                        default => $state,
                    })
                    ->colors([
                        'gray' => 'draft',
                        'info' => 'issued',
                        'warning' => 'partially_paid',
                        'success' => 'paid',
                        'danger' => 'overdue',
                    ])
                    ->toggleable(),
            ])
            ->defaultSort('bill_date', 'desc')
            ->filters([
                Tables\Filters\Filter::make('billing_period')
                    ->form([
                        Forms\Components\Select::make('period')
                            ->label('الفترة')
                            ->options(function () {
                                $periods = \App\Models\Invoice::select('billing_period_start', 'billing_period_end')
                                    ->distinct()
                                    ->orderBy('billing_period_start', 'desc')
                                    ->get();

                                $options = [];
                                foreach ($periods as $period) {
                                    if ($period->billing_period_start && $period->billing_period_end) {
                                        $key = $period->billing_period_start->format('Y-m-d') . '|' . $period->billing_period_end->format('Y-m-d');
                                        // Format as Y-m
                                        $label = 'من: ' . \Carbon\Carbon::parse($period->billing_period_start)->format('Y-m-d') . ' إلى: ' . \Carbon\Carbon::parse($period->billing_period_end)->format('Y-m-d');
                                        $options[$key] = $label;
                                    }
                                }
                                return $options;
                            })
                            ->searchable()
                            ->preload(),
                    ])
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): \Illuminate\Database\Eloquent\Builder {
                        if (isset($data['period']) && $data['period']) {
                            $parts = explode('|', $data['period']);
                            if (count($parts) === 2) {
                                $query->whereDate('billing_period_start', $parts[0])
                                      ->whereDate('billing_period_end', $parts[1]);
                            }
                        }
                        return $query;
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if (isset($data['period']) && $data['period']) {
                            $parts = explode('|', $data['period']);
                            if (count($parts) === 2) {
                                $label = 'من ' . \Carbon\Carbon::parse($parts[0])->format('Y-m-d') . ' إلى ' . \Carbon\Carbon::parse($parts[1])->format('Y-m-d');
                                $indicators[] = Tables\Filters\Indicator::make('الفترة: ' . $label)
                                    ->removeField('period');
                            }
                        }
                        return $indicators;
                    }),

                Tables\Filters\SelectFilter::make('square')
                    ->label('المربع السكني')
                    ->relationship('subscriber.square', 'name')
                    ->getOptionLabelFromRecordUsing(fn (\App\Models\Square $record): string => $record->display_name)
                    ->searchable(['name', 'code'])
                    ->preload(),

                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'draft' => 'مسودة',
                        'issued' => 'صادرة',
                        'partially_paid' => 'مسددة جزئياً',
                        'paid' => 'مسددة',
                        'overdue' => 'متأخرة',
                        'cancelled' => 'ملغاة',
                    ]),

                Tables\Filters\SelectFilter::make('subscriber_id')
                    ->label('المشترك')
                    ->relationship('subscriber', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                \Filament\Actions\Action::make('generate_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('primary')
                    ->action(function (Invoice $record) {
                        $record->load(['subscriber.square', 'items']);

                        $tempDir = storage_path('app/temp');
                        if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
                        $filePath = $tempDir . "/invoice-{$record->bill_number}.pdf";

                        Pdf::view('pdf.invoice', [
                            'invoice' => $record,
                        ])
                            ->withBrowsershot(function ($browsershot) {
                                $browsershot->noSandbox();
                            })
                            ->margins(0, 0, 0, 0, Unit::Millimeter)
                            ->format('A5')
                            ->landscape()
                            ->save($filePath);

                        return response()->download($filePath)->deleteFileAfterSend(true);
                    }),

                \Filament\Actions\Action::make('send_whatsapp_link')
                    ->label('إرسال واتساب')
                    ->icon('heroicon-o-chat-bubble-oval-left-ellipsis')
                    ->color('success')
                    ->url(fn(Invoice $record) => app(\App\Services\WhatsAppService::class)->getInvoiceMessageUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn(Invoice $record) => app(\App\Services\WhatsAppService::class)->isEnabled() && app(\App\Services\WhatsAppService::class)->getMethod() === 'click_to_chat' && app(\App\Services\WhatsAppService::class)->getInvoiceMessageUrl($record) !== null),

                \Filament\Actions\Action::make('send_whatsapp_api')
                    ->label('إرسال واتساب (PDF)')
                    ->icon('heroicon-o-document-arrow-up')
                    ->color('success')
                    ->action(function (Invoice $record) {
                        \App\Jobs\SendWhatsAppInvoiceJob::dispatch($record);
                        \Filament\Notifications\Notification::make()
                            ->title('جاري الإرسال')
                            ->body('تمت إضافة الفاتورة لطابور الإرسال إلى واتساب كملف PDF.')
                            ->success()
                            ->send();
                    })
                    ->visible(fn(Invoice $record) => app(\App\Services\WhatsAppService::class)->isEnabled() && in_array(app(\App\Services\WhatsAppService::class)->getMethod(), ['meta_api', 'local_api'])),

                \Filament\Actions\Action::make('print')
                    ->label('طباعة')
                    ->icon('heroicon-o-printer')
                    ->color('secondary')
                    ->url(fn(Invoice $record) => route('invoices.print', $record))
                    ->openUrlInNewTab(),

                \Filament\Actions\Action::make('cancel_invoice')
                    ->label('إلغاء الفاتورة')
                    ->color('danger')
                    ->visible(fn (Invoice $record) => in_array($record->status, ['issued', 'partially_paid', 'paid', 'overdue'], true)
                        && auth()->user()?->can('delete', $record))
                    ->action(function (Invoice $record, array $data) {
                        \Illuminate\Support\Facades\Gate::authorize('delete', $record);
                        app(\App\Services\InvoiceCancellationService::class)->cancel($record, $data['reason'], auth()->id(), 'web');
                    })
                    ->form([Forms\Components\Textarea::make('reason')->label('سبب الإلغاء')->required()->minLength(10)->maxLength(1000)])
                    ->requiresConfirmation(),
                \Filament\Actions\Action::make('collect_payment')
                    ->label('تحصيل')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(function (Invoice $record) {
                        if (!in_array($record->status, ['issued', 'partially_paid', 'overdue'])) {
                            return false;
                        }

                        return $record->isLatestInvoice() && $record->getCollectibleAmount() > 0;
                    })
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('المبلغ')
                            ->default(fn (Invoice $record) => $record->getCollectibleAmount())
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->prefix('ريال'),

                        Forms\Components\Select::make('payment_method')
                            ->label('طريقة الدفع')
                            ->options([
                                'cash' => 'نقدي',
                                'bank_transfer' => 'تحويل بنكي',
                                'check' => 'شيك',
                            ])
                            ->default('cash')
                            ->reactive()
                            ->required(),

                        Forms\Components\TextInput::make('reference_number')
                            ->label('رقم الحوالة / الشيك')
                            ->visible(fn(callable $get) => in_array($get('payment_method'), ['bank_transfer', 'check']))
                            ->required(fn(callable $get) => in_array($get('payment_method'), ['bank_transfer', 'check'])),

                        Forms\Components\TextInput::make('reason')
                            ->label('وذلك مقابل')
                            ->nullable(),

                        Forms\Components\Textarea::make('notes')
                            ->label('ملاحظات')
                            ->rows(2),
                    ])
                    ->action(function (Invoice $record, array $data): void {
                        $service = new PaymentService();
                        $payment = $service->collectPayment(
                            subscriber: $record->subscriber,
                            amount: (float) $data['amount'],
                            invoiceId: $record->id,
                            paymentMethod: $data['payment_method'],
                            referenceNumber: $data['reference_number'] ?? null,
                            notes: $data['notes'] ?? null,
                            reason: $data['reason'] ?? null,
                        );

                        Notification::make()
                            ->title('تم التحصيل بنجاح')
                            ->body("سند قبض: {$payment->receipt_number}")
                            ->success()
                            ->send();
                    }),

            ])
            ->headerActions([

                \Filament\Actions\Action::make('generate_invoice')
                    ->label('إصدار فاتورة عداد')
                    ->icon('heroicon-o-calculator')
                    ->color('primary')
                    ->form([
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
                            ->reactive()
                            ->afterStateUpdated(function (callable $set, callable $get, $state) {
                                if (!$state) {
                                    $set('meter_id', null);
                                    return;
                                }

                                $subIdInt = $state;
                                $subscriber = \App\Models\Subscriber::find($state);
                                if ($subscriber && $subscriber->currentMeter) {
                                    $set('meter_id', $subscriber->currentMeter->id);
                                } else {
                                    $set('meter_id', null);
                                }


                            }),

                        Forms\Components\Select::make('meter_id')
                            ->label('العداد')
                            ->options(function (callable $get) {
                                $subscriber = Subscriber::find($get('subscriber_id'));
                                if (!$subscriber) return [];
                                return $subscriber->currentMeter()->pluck('unique_code', 'id');
                            })
                            ->required()
                            ->visible(fn(callable $get) => filled($get('subscriber_id')))
                            ->searchable(),


                        Forms\Components\Textarea::make('notes')
                            ->label('ملاحظات')
                            ->rows(2),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $subIdInt = $data['subscriber_id'];
                            $subscriber = Subscriber::find($subIdInt);
                            $meter = \App\Models\Meter::find($data['meter_id']);
                            $service = new InvoiceService();

                            $invoice = $service->generateInvoice(
                                subscriber: $subscriber,
                                meter: $meter,
                                notes: $data['notes'] ?? null,
                                includeReadings: true,
                                invoiceType: 'meter'
                            );

                            \Filament\Notifications\Notification::make()
                                ->title('تم إنشاء الفاتورة')
                                ->body("فاتورة رقم: {$invoice->bill_number} - الإجمالي: {$invoice->total_amount} ريال")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('عفواً، لا يمكن إنشاء الفاتورة')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([

                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}

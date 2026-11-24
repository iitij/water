<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use Spatie\LaravelPdf\Facades\Pdf;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';

    protected static string | \UnitEnum | null $navigationGroup = 'العمليات';

    protected static ?string $modelLabel = 'سند قبض';

    protected static ?string $pluralModelLabel = 'المدفوعات';

    protected static ?int $navigationSort = 3;

    public static function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema
    {
        return $form->schema([
            \Filament\Schemas\Components\Section::make('بيانات الدفع')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('subscriber_id')
                        ->label('المشترك')
                        ->relationship('subscriber', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function (?string $state, $set, $get) {
                            $set('invoice_id', null);
                            $set('invoice_amount', null);
                            $set('amount', null);

                            if ($state) {
                                $latestUnpaidInvoice = \App\Models\Invoice::where('subscriber_id', $state)
                                    ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                                    ->orderBy('id', 'desc')
                                    ->first();
                                if ($latestUnpaidInvoice) {
                                    $set('invoice_id', $latestUnpaidInvoice->id);
                                    $due = $latestUnpaidInvoice->getCollectibleAmount();
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
                        }),

                    Forms\Components\Select::make('invoice_id')
                        ->label('الفاتورة (اختياري)')
                        ->relationship(
                            'invoice', 
                            'bill_number',
                            fn (\Illuminate\Database\Eloquent\Builder $query, $get) => 
                                $query->where('subscriber_id', $get('subscriber_id'))
                                      ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                        )
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function ($state, $set, $get) {
                            $set('invoice_amount', null);
                            $set('amount', null);

                            if ($state) {
                                $inv = \App\Models\Invoice::where('subscriber_id', $get('subscriber_id'))
                                    ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                                    ->find($state);
                                if ($inv) {
                                    $due = $inv->getCollectibleAmount();
                                    $set('invoice_amount', $due);
                                    $set('amount', $due);
                                }
                            } else {
                                $set('invoice_amount', null);
                            }
                        }),

                    Forms\Components\TextInput::make('invoice_amount')
                        ->label('المبلغ المستحق')
                        ->disabled()
                        ->dehydrated(false)
                        ->numeric()
                        ->prefix('ريال'),

                    Forms\Components\TextInput::make('receipt_number')
                        ->label('رقم سند القبض')
                        ->default(fn () => Payment::generateReceiptNumber())
                        ->disabled()
                        ->dehydrated(),

                    Forms\Components\TextInput::make('amount')
                        ->label('المبلغ')
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->prefix('ريال'),

                    Forms\Components\DatePicker::make('payment_date')
                        ->label('تاريخ الدفع')
                        ->default(now())
                        ->required(),

                    Forms\Components\Select::make('payment_method')
                        ->label('طريقة الدفع')
                        ->options([
                            'cash' => 'نقدي',
                            'bank_transfer' => 'تحويل بنكي',
                            'check' => 'شيك',
                        ])
                        ->default('cash')
                        ->required(),

                    Forms\Components\TextInput::make('reason')
                        ->label('وذلك مقابل')
                        ->columnSpanFull()
                        ->nullable(),

                    Forms\Components\Textarea::make('notes')
                        ->label('ملاحظات')
                        ->columnSpanFull()
                        ->rows(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('receipt_number')
                    ->label('رقم سند القبض')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('subscriber.name')
                    ->label('المشترك')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('المبلغ')
                    ->numeric(2)->suffix(' ريال')
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_date')
                    ->label('التاريخ')
                    ->date('Y-m-d')
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('طريقة الدفع')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cash' => 'نقدي',
                        'bank_transfer' => 'تحويل بنكي',
                        'check' => 'شيك',
                        default => $state,
                    })
                    ->badge(),

                Tables\Columns\TextColumn::make('invoice.bill_number')
                    ->label('رقم الفاتورة')
                    ->placeholder('غير محدد'),
            ])
            ->defaultSort('payment_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('طريقة الدفع')
                    ->options([
                        'cash' => 'نقدي',
                        'bank_transfer' => 'تحويل بنكي',
                        'check' => 'شيك',
                    ]),
            ])
            ->actions([
                \Filament\Actions\Action::make('generate_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('primary')
                    ->action(function (Payment $record) {
                        $record->load(['subscriber.square', 'invoice']);

                        $tempDir = storage_path('app/temp');
                        if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
                        $filePath = $tempDir . "/receipt-{$record->receipt_number}.pdf";

                        \Spatie\LaravelPdf\Facades\Pdf::view('pdf.receipt', [
                            'payment' => $record,
                        ])
                            ->withBrowsershot(function ($browsershot) {
                                $browsershot->noSandbox();
                            })
                            ->margins(0, 0, 0, 0, \Spatie\LaravelPdf\Enums\Unit::Millimeter)
                            ->format('A5')
                            ->landscape()
                            ->save($filePath);

                        return response()->download($filePath)->deleteFileAfterSend(true);
                    }),
                    
                \Filament\Actions\Action::make('send_whatsapp_link')
                    ->label('إرسال واتساب')
                    ->icon('heroicon-o-chat-bubble-oval-left-ellipsis')
                    ->color('success')
                    ->url(fn (Payment $record) => app(\App\Services\WhatsAppService::class)->getPaymentMessageUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (Payment $record) => app(\App\Services\WhatsAppService::class)->isEnabled() && app(\App\Services\WhatsAppService::class)->getMethod() === 'click_to_chat' && app(\App\Services\WhatsAppService::class)->getPaymentMessageUrl($record) !== null),

                \Filament\Actions\Action::make('send_whatsapp_api')
                    ->label('إرسال واتساب (PDF)')
                    ->icon('heroicon-o-document-arrow-up')
                    ->color('success')
                    ->action(function (Payment $record) {
                        \App\Jobs\SendWhatsAppPaymentJob::dispatch($record);
                        \Filament\Notifications\Notification::make()
                            ->title('جاري الإرسال')
                            ->body('تمت إضافة السند لطابور الإرسال إلى واتساب كملف PDF.')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (Payment $record) => app(\App\Services\WhatsAppService::class)->isEnabled() && in_array(app(\App\Services\WhatsAppService::class)->getMethod(), ['meta_api', 'local_api'])),

                \Filament\Actions\Action::make('print')
                    ->label('طباعة')
                    ->icon('heroicon-o-printer')
                    ->color('secondary')
                    ->url(fn (Payment $record) => route('payments.print', $record))
                    ->openUrlInNewTab(),
                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'create' => Pages\CreatePayment::route('/create'),
            'edit' => Pages\EditPayment::route('/{record}/edit'),
        ];
    }
}






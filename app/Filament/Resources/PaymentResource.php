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
                    Forms\Components\Hidden::make('sync_uuid')
                        ->default(fn () => (string) \Illuminate\Support\Str::uuid())
                        ->dehydrated(fn (?Payment $record): bool => $record === null && app(\App\Services\ProspectiveCashReceiptEntry::class)->enabled())
                        ->required(fn (?Payment $record): bool => $record === null && app(\App\Services\ProspectiveCashReceiptEntry::class)->enabled())
                        ->rules(['nullable', 'uuid']),

                    Forms\Components\Select::make('cash_session_id')
                        ->label('صندوق التحصيل الأصلي')
                        ->placeholder('بدون صندوق محصّل')
                        ->options(fn () => \App\Models\CashSession::query()->open()
                            ->where('user_id', auth()->id())->orderBy('id')
                            ->get()->mapWithKeys(fn ($session) => [$session->id => '#'.$session->id.' — '.$session->session_date->toDateString()])->all())
                        ->visible(fn (?Payment $record): bool => $record === null && app(\App\Services\ProspectiveCashReceiptEntry::class)->enabled())
                        ->dehydrated(fn (?Payment $record): bool => $record === null && app(\App\Services\ProspectiveCashReceiptEntry::class)->enabled())
                        ->helperText('إذا كان لديك صندوق مفتوح، يجب اختياره صراحةً. لا يتم نقل السند إلى صندوق آخر.'),

                    Forms\Components\Select::make('subscriber_id')
                        ->disabled(fn (?Payment $record) => $record !== null)
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
                                $latestUnpaidInvoice = \App\Models\Invoice::collectibleForSubscriber((int) $state)
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
                        ->disabled(fn (?Payment $record) => $record !== null)
                        ->label('الفاتورة (اختياري)')
                        ->relationship(
                            'invoice', 
                            'bill_number',
                            fn (\Illuminate\Database\Eloquent\Builder $query, $get) => 
                                $query->collectibleForSubscriber($get('subscriber_id') ? (int) $get('subscriber_id') : null)
                        )
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function ($state, $set, $get) {
                            $set('invoice_amount', null);
                            $set('amount', null);

                            if ($state) {
                                $inv = \App\Models\Invoice::collectibleForSubscriber($get('subscriber_id') ? (int) $get('subscriber_id') : null)
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
                        ->placeholder('يُولّد عند إصدار السند')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('amount')
                        ->disabled(fn (?Payment $record) => $record !== null)
                        ->label('المبلغ')
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->prefix('ريال'),

                    Forms\Components\DatePicker::make('payment_date')
                        ->disabled(fn (?Payment $record) => $record !== null)
                        ->label('تاريخ الدفع')
                        ->default(fn () => now('Asia/Aden')->toDateString())
                        ->required(),

                    Forms\Components\Select::make('payment_method')
                        ->disabled(fn (?Payment $record) => $record !== null)
                        ->label('طريقة الدفع')
                        ->options(fn (?Payment $record) => $record === null && app(\App\Services\ProspectiveCashReceiptEntry::class)->enabled()
                            ? ['cash' => 'نقدي']
                            : ['cash' => 'نقدي', 'bank_transfer' => 'تحويل بنكي', 'check' => 'شيك'])
                        ->default('cash')
                        ->required(),

                    Forms\Components\TextInput::make('reason')
                        ->disabled(fn (?Payment $record) => $record !== null)
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

                Tables\Columns\TextColumn::make('refunds_sum_amount')
                    ->sum('refunds', 'amount')->label('مبلغ مسترد')->numeric(2)->placeholder('—'),
                Tables\Columns\TextColumn::make('accountTransfer.to_subscriber_id')
                    ->label('محول إلى حساب')
                    ->placeholder('—'),
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
                ...\App\Filament\Actions\ProspectiveReceiptVoidActions::make(),
                \Filament\Actions\Action::make('view_allocations')
                    ->label('تخصيصات السند')
                    ->icon('heroicon-o-list-bullet')
                    ->visible(fn (Payment $record) => auth()->user()?->can('view', $record))
                    ->modalHeading('توزيع السند الحالي')
                    ->modalContent(function (Payment $record) {
                        \Illuminate\Support\Facades\Gate::authorize('view', $record);
                        return view('filament.accounting.allocation-snapshot', [
                            'snapshot' => app(\App\Services\PaymentAllocationReadService::class)->forPayment($record),
                        ]);
                    })->modalSubmitAction(false)->modalCancelActionLabel('إغلاق'),
                \Filament\Actions\Action::make('record_refund')
                    ->label('تسجيل صرف رصيد دائن')
                    ->color('warning')
                    ->visible(function (Payment $record): bool {
                        $user = auth()->user();
                        return $user && ! $record->isVoided() && ! $record->accountTransfer()->exists()
                            && ($user->hasRole('super_admin') || ($user->can('cash_sessions.approve') && $user->can('payments.void.approve')));
                    })
                    ->form([
                        Forms\Components\Hidden::make('operation_uuid')
                            ->default(fn () => (string) \Illuminate\Support\Str::uuid())
                            ->dehydrated(fn () => app(\App\Services\ProspectiveRefundEntry::class)->enabled())
                            ->required(fn () => app(\App\Services\ProspectiveRefundEntry::class)->enabled()),
                        Forms\Components\Select::make('treasury_account_id')
                            ->label('حساب الخزينة الذي تم الصرف منه')
                            ->options(fn (\Filament\Schemas\Components\Utilities\Get $get): array =>
                                \Illuminate\Support\Facades\DB::table('treasury_accounts')
                                    ->where('channel', match ($get('method')) {
                                        'treasury_cash' => 'cash', 'bank_transfer' => 'bank', default => '',
                                    })->orderBy('id')->get()
                                    ->mapWithKeys(fn ($account) => [$account->id => $account->code.' — '.$account->name])->all())
                            ->visible(fn () => app(\App\Services\ProspectiveRefundEntry::class)->enabled())
                            ->dehydrated(fn () => app(\App\Services\ProspectiveRefundEntry::class)->enabled())
                            ->required(fn () => app(\App\Services\ProspectiveRefundEntry::class)->enabled()),
                        Forms\Components\TextInput::make('amount')->label('المبلغ المصروف فعلياً')->numeric()->required()->minValue(0.01),
                        Forms\Components\TextInput::make('disbursement_reference')->label('مرجع مستند الصرف الخارجي')->required()->minLength(3)->maxLength(150),
                        Forms\Components\Select::make('method')->label('مصدر الصرف')->options(['treasury_cash' => 'الخزينة الرئيسية', 'bank_transfer' => 'تحويل بنكي'])->live()->required(),
                        Forms\Components\DatePicker::make('disbursed_on')->label('تاريخ الصرف الفعلي')->default(now())->maxDate(now())->required(),
                        Forms\Components\Textarea::make('reason')->label('سبب الاسترداد')->required()->minLength(10)->maxLength(1000),
                        Forms\Components\Textarea::make('evidence')->label('دليل الصرف والاستلام')->required()->minLength(10)->maxLength(2000),
                        Forms\Components\Checkbox::make('disbursement_confirmed')->label('تم الصرف فعلياً خارج صندوق المحصل')->accepted()->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Payment $record, array $data): void {
                        abort_unless($data['disbursement_confirmed'] ?? false, 422);
                        try {
                            app(\App\Services\ProspectiveRefundEntry::class)->record(auth()->user(), $record, $data);
                        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $error) {
                            throw \Illuminate\Validation\ValidationException::withMessages(['data' => $error->getMessage()]);
                        }
                        \Filament\Notifications\Notification::make()->title('تم تسجيل الاسترداد المثبت')->success()->send();
                    }),
                \Filament\Actions\Action::make('transfer_account')
                    ->label('تصحيح حساب المشترك')
                    ->color('warning')
                    ->visible(function (Payment $record): bool {
                        $user = auth()->user();
                        return $user && ! $record->isVoided() && ! $record->accountTransfer()->exists()
                            && ($user->hasRole('super_admin') || ($user->can('cash_sessions.approve') && $user->can('payments.void.approve')));
                    })
                    ->form([
                        Forms\Components\Hidden::make('operation_uuid')
                            ->default(fn () => (string) \Illuminate\Support\Str::uuid())
                            ->dehydrated(fn () => app(\App\Services\ProspectiveSubscriberTransferEntry::class)->enabled())
                            ->required(fn () => app(\App\Services\ProspectiveSubscriberTransferEntry::class)->enabled()),
                        Forms\Components\Select::make('to_subscriber_id')
                            ->label('المشترك الصحيح')->searchable()->required()
                            ->getSearchResultsUsing(fn (string $search) => \App\Models\Subscriber::where('name', 'like', '%'.$search.'%')->limit(50)->pluck('name', 'id')->all())
                            ->getOptionLabelUsing(fn ($value) => \App\Models\Subscriber::find($value)?->name),
                        Forms\Components\Textarea::make('reason')->label('سبب التصحيح')->required()->minLength(10)->maxLength(1000),
                        Forms\Components\Textarea::make('evidence')->label('دليل هوية صاحب الدفعة')->required()->minLength(10)->maxLength(2000),
                        Forms\Components\Checkbox::make('no_new_cash_movement')->label('لا توجد حركة نقدية جديدة')->accepted()->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Payment $record, array $data): void {
                        abort_unless($data['no_new_cash_movement'] ?? false, 422);
                        try {
                            app(\App\Services\ProspectiveSubscriberTransferEntry::class)->transfer(auth()->user(), $record, $data);
                        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $error) {
                            throw \Illuminate\Validation\ValidationException::withMessages(['data' => $error->getMessage()]);
                        }
                        \Filament\Notifications\Notification::make()->title('تم تصحيح تخصيص المبلغ دون قبض جديد')->success()->send();
                    }),
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
                \Filament\Actions\EditAction::make()->label('تعديل الملاحظات'),
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






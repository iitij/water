<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriberResource\Pages;
use App\Filament\Resources\SubscriberResource\RelationManagers;
use App\Livewire\SubscriberTransactionsTable;
use App\Models\Square;
use App\Models\Subscriber;
use App\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;

class SubscriberResource extends Resource
{
    protected static ?string $model = Subscriber::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المشتركين';

    protected static ?string $modelLabel = 'مشترك';

    protected static ?string $pluralModelLabel = 'المشتركون';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('البيانات الأساسية')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('اسم المشترك')
                        ->required()
                        ->maxLength(255),

                    PhoneInput::make('phone')
                        ->label('رقم الهاتف')
                        ->defaultCountry('YE')
                        ->initialCountry('ye'),

                    Forms\Components\Select::make('square_id')
                        ->label('المربع السكني')
                        ->relationship('square', 'name')
                        ->getOptionLabelFromRecordUsing(fn (Square $record): string => $record->display_name)
                        ->searchable(['name', 'code'])
                        ->required()

                        ->preload(),

                    Forms\Components\Select::make('status')
                        ->label('الحالة')
                        ->options([
                            'active' => 'نشط',
                            'inactive' => 'غير نشط',
                            'suspended' => 'موقوف',
                        ])
                        ->default('active')
                        ->required(),

                    Forms\Components\DatePicker::make('subscription_start_date')
                        ->label('تاريخ بداية الاشتراك')
                        ->default(now()),

                    Forms\Components\Textarea::make('address')
                        ->label('العنوان')
                        ->columnSpanFull()
                        ->rows(2),
                ]),

            Section::make('الحساب المالي وسجل الحركات')
                ->visible(fn ($record) => $record !== null)
                ->schema([
                    Forms\Components\TextInput::make('credit_balance')
                        ->label('الرصيد الدائن للحساب الموحد')
                        ->formatStateUsing(function ($record) {
                            if (! $record) {
                                return 0;
                            }
                            return number_format($record->getAccountCredit(), 2);
                        })
                        ->suffix('ريال')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('debit_balance')
                        ->label('الرصيد المدين للحساب الموحد')
                        ->formatStateUsing(function ($record) {
                            if (! $record) {
                                return 0;
                            }
                            return number_format($record->getAccountDebt(), 2);
                        })
                        ->suffix('ريال')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\Repeater::make('meters')
                        ->relationship('meters')
                        ->label('بيانات العداد (مرجع الحركات)')
                        ->schema([
                            Forms\Components\TextInput::make('meter_number')
                                ->label('رقم العداد')
                                ->formatStateUsing(fn ($record) => $record ? 'عداد رقم: '.$record->id.' ('.$record->unique_code.')' : '-')
                                ->disabled()
                                ->dehydrated(false),
                        ])
                        ->columns(3)
                        ->disableItemCreation()
                        ->disableItemDeletion()
                        ->disableItemMovement()
                        ->columnSpanFull()
                        ->dehydrated(false)
                        ->visible(fn ($record) => $record && $record->meters()->count() > 0),

                    Livewire::make(SubscriberTransactionsTable::class)
                        ->key('transactions-table')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('subscription_start_date')
                    ->label('بداية الاشتراك')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('الهاتف')
                    ->extraAttributes(['dir' => 'ltr'])
                    ->searchable(),

                Tables\Columns\TextColumn::make('square.name')
                    ->label('المربع السكني')
                    ->formatStateUsing(fn ($state, $record) => $record->square?->display_name)
                    ->searchable(['name', 'code'])
                    ->sortable(),

                Tables\Columns\TextColumn::make('account_balance')
                    ->label('صافي الحساب')
                    ->state(fn (Subscriber $record) => $record->getAccountBalance())
                    ->numeric(2)->suffix(' ريال'),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'نشط',
                        'inactive' => 'غير نشط',
                        'suspended' => 'موقوف',
                        default => $state,
                    })
                    ->colors([
                        'success' => 'active',
                        'warning' => 'suspended',
                        'danger' => 'inactive',
                    ]),

                Tables\Columns\TextColumn::make('currentMeter.unique_code')
                    ->label('العداد الحالي')
                    ->placeholder('بانتظار تركيب عداد')
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('square_id')
                    ->label('المربع السكني')
                    ->relationship('square', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Square $record): string => $record->display_name)
                    ->searchable(['name', 'code']),

                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'active' => 'نشط',
                        'inactive' => 'غير نشط',
                        'suspended' => 'موقوف',
                    ]),
            ])
            ->actions([
                Action::make('collect_payment')
                    ->label('تحصيل دفعة')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('المبلغ')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->prefix('ريال'),

                        Forms\Components\Select::make('invoice_id')
                            ->label('الفاتورة (اختياري)')
                            ->options(function ($record) {
                                return $record->invoices()
                                    ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                                    ->pluck('bill_number', 'id');
                            })
                            ->placeholder('تطبيق على الأقدم تلقائياً')
                            ->searchable(),

                        Forms\Components\Select::make('payment_method')
                            ->label('طريقة الدفع')
                            ->options([
                                'cash' => 'نقدي',
                                'bank_transfer' => 'تحويل بنكي',
                                'check' => 'شيك',
                            ])
                            ->default('cash')
                            ->required(),

                        Forms\Components\Textarea::make('notes')
                            ->label('ملاحظات')
                            ->rows(2),
                    ])
                    ->action(function (Subscriber $record, array $data): void {
                        $service = new PaymentService;
                        $payment = $service->collectPayment(
                            subscriber: $record,
                            amount: (float) $data['amount'],
                            invoiceId: $data['invoice_id'] ?? null,
                            paymentMethod: $data['payment_method'],
                            notes: $data['notes'] ?? null,
                        );

                        Notification::make()
                            ->title('تم التحصيل بنجاح')
                            ->body("سند قبض رقم: {$payment->receipt_number}")
                            ->success()
                            ->send();
                    }),


                Action::make('ledger_statement')
                    ->label('كشف الحساب')
                    ->icon('heroicon-o-document-text')
                    ->visible(fn (Subscriber $record): bool => static::canView($record))
                    ->url(fn (Subscriber $record): string => static::getUrl('ledger-statement', ['record' => $record])),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MetersRelationManager::class,
            RelationManagers\InvoicesRelationManager::class,
            RelationManagers\PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscribers::route('/'),
            'create' => Pages\CreateSubscriber::route('/create'),
            'edit' => Pages\EditSubscriber::route('/{record}/edit'),
            'ledger-statement' => Pages\SubscriberLedgerStatement::route('/{record}/ledger-statement'),
        ];
    }
}

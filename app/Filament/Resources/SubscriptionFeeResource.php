<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriptionFeeResource\Pages;
use App\Models\Subscriber;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Schemas\Components;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;

class SubscriptionFeeResource extends Resource
{
    protected static ?string $model = \App\Models\SubscriptionFee::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-ticket';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة المشتركين';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'رسوم اشتراك';

    protected static ?string $pluralModelLabel = 'طلبات الاشتراك';

    protected static ?string $slug = 'subscription-fees';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Components\Section::make('تفاصيل رسوم الاشتراك')
                    ->schema([
                        Forms\Components\Select::make('subscriber_id')
                            ->label('المشترك')
                            ->relationship('subscriber', 'name')
                            ->required()
                            ->searchable()
                            ->reactive()
                            ->columnSpan(1),
                            
                        Forms\Components\Select::make('meter_id')
                            ->label('العداد')
                            ->options(function (callable $get) {
                                $subId = $get('subscriber_id');
                                if (!$subId) return [];
                                return \App\Models\Meter::where('subscriber_id', $subId)->pluck('unique_code', 'id');
                            })
                            ->searchable()
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('amount')
                            ->label('رسوم الاشتراك')
                            ->numeric()
                            ->required()
                            ->prefix('ريال')
                            ->columnSpan(1),

                        Forms\Components\Select::make('status')
                            ->label('الحالة')
                            ->options([
                                'uninvoiced' => 'غير مفوترة',
                                'invoiced' => 'مفوترة',
                                'partially_paid' => 'مسدد جزئياً',
                                'paid' => 'مسدد',
                            ])
                            ->default('uninvoiced')
                            ->required()
                            ->columnSpan(1),
                            
                        Forms\Components\Textarea::make('notes')
                            ->label('ملاحظات')
                            ->nullable()
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('subscriber.name')
                    ->label('المشترك')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('meter.unique_code')
                    ->label('العداد')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('الرسوم الأساسية')
                    ->numeric(2)
                    ->suffix(' ريال')
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->numeric(2)
                            ->label('الإجمالي')
                    ),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('الحالة')
                    ->colors([
                        'danger' => 'uninvoiced',
                        'warning' => 'invoiced',
                        'primary' => 'partially_paid',
                        'success' => 'paid',
                    ])
                    ->formatStateUsing(function ($state) {
                        return match ($state) {
                            'uninvoiced' => 'غير مفوترة',
                            'invoiced' => 'مفوترة',
                            'partially_paid' => 'مسدد جزئياً',
                            'paid' => 'مسدد',
                            default => $state,
                        };
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('invoice.bill_number')
                    ->label('رقم الفاتورة')
                    ->url(fn ($record) => $record->invoice_id ? InvoiceResource::getUrl('edit', ['record' => $record->invoice_id]) : null)
                    ->color('primary')
                    ->searchable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإضافة')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'uninvoiced' => 'غير مفوترة',
                        'invoiced' => 'مفوترة',
                        'partially_paid' => 'مسدد جزئياً',
                        'paid' => 'مسدد',
                    ]),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionFees::route('/'),
            'edit' => Pages\EditSubscriptionFee::route('/{record}/edit'),
        ];
    }
}

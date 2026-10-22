<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceRequestResource\Pages;
use App\Models\ServiceRequest;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ServiceRequestResource extends Resource
{
    protected static ?string $model = ServiceRequest::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-truck';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة المشتركين';

    protected static ?string $modelLabel = 'طلب وايت';

    protected static ?string $pluralModelLabel = 'طلبات الوايت';

    protected static ?int $navigationSort = 3;

    public static function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema
    {
        return $form
            ->columns(1)
            ->schema([
                \Filament\Schemas\Components\Section::make('بيانات الطلب')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('subscriber_id')
                        ->label('المشترك')
                        ->relationship('subscriber', 'name')
                        ->required()
                        ->searchable()
                        ->preload(),

                    Forms\Components\Hidden::make('service_type')
                        ->default('water_tanker'),

                    Forms\Components\TextInput::make('amount')
                        ->label('سعر الوايت')
                        ->numeric()
                        ->required()
                        ->prefix('ريال'),

                    Forms\Components\DatePicker::make('service_date')
                        ->label('تاريخ الخدمة')
                        ->default(now())
                        ->required(),

                    Forms\Components\Select::make('status')
                        ->label('الحالة')
                        ->options([
                            'pending' => 'معلق',
                            'completed' => 'مكتمل',
                            'invoiced' => 'مفوتر',
                            'paid' => 'مسدد',
                        ])
                        ->default('completed')
                        ->required(),

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
                Tables\Columns\TextColumn::make('subscriber.name')
                    ->label('المشترك')
                    ->searchable()
                    ->sortable()
                    ->description(fn (\App\Models\ServiceRequest $record): ?string => $record->notes ? '📝 يوجد ملاحظة' : null),

                Tables\Columns\TextColumn::make('amount')
                    ->label('المبلغ')
                    ->numeric(2)
                    ->suffix(' ريال')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('الإجمالي')),

                Tables\Columns\TextColumn::make('service_date')
                    ->label('التاريخ')
                    ->date('Y-m-d')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'معلق',
                        'completed' => 'مكتمل',
                        'invoiced' => 'مفوتر',
                        'paid' => 'مسدد',
                        default => $state,
                    })
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'completed',
                        'primary' => 'invoiced',
                        'success' => 'paid',
                    ]),
                    
                Tables\Columns\TextColumn::make('invoice.bill_number')
                    ->label('الفاتورة')
                    ->url(fn (\App\Models\ServiceRequest $record) => $record->invoice_id ? InvoiceResource::getUrl('edit', ['record' => $record->invoice_id]) : null)
                    ->color('primary')
                    ->placeholder('-'),
            ])
            ->defaultSort('service_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'pending' => 'معلق',
                        'completed' => 'مكتمل',
                        'invoiced' => 'مفوتر',
                        'paid' => 'مسدد',
                    ]),
                Tables\Filters\Filter::make('service_date')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_from')->label('من تاريخ'),
                        \Filament\Forms\Components\DatePicker::make('created_until')->label('إلى تاريخ'),
                    ])
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): \Illuminate\Database\Eloquent\Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (\Illuminate\Database\Eloquent\Builder $query, $date): \Illuminate\Database\Eloquent\Builder => $query->whereDate('service_date', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (\Illuminate\Database\Eloquent\Builder $query, $date): \Illuminate\Database\Eloquent\Builder => $query->whereDate('service_date', '<=', $date),
                            );
                    })
            ])
            ->actions([
                \Filament\Actions\Action::make('mark_completed')
                    ->label('إنجاز')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (\App\Models\ServiceRequest $record) => $record->status === 'pending')
                    ->action(function (\App\Models\ServiceRequest $record) {
                        $record->update(['status' => 'completed']);
                    })
                    ->requiresConfirmation(),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServiceRequests::route('/'),
        ];
    }
}






<?php

namespace App\Filament\Resources\VoidRequests\Tables;

use App\Models\Payment;
use App\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Throwable;

class VoidRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('void_requested_at')
            ->columns([
                Tables\Columns\TextColumn::make('receipt_number')
                    ->label('رقم السند')
                    ->searchable(),
                Tables\Columns\TextColumn::make('subscriber.name')
                    ->label('المشترك')
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('المبلغ')
                    ->numeric(2)
                    ->sortable(),
                Tables\Columns\TextColumn::make('void_request_reason')
                    ->label('سبب الطلب')
                    ->wrap()
                    ->limit(80),
                Tables\Columns\TextColumn::make('voidRequester.name')
                    ->label('طلبَه')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('void_requested_at')
                    ->label('وقت الطلب')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('cash_session_id')
                    ->label('الصندوق')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([])
            ->recordActions([
                Action::make('approve_void')
                    ->label('اعتماد الإلغاء')
                    ->icon('heroicon-o-check-badge')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('اعتماد إلغاء السند')
                    ->modalDescription('سيُعلَّم السند ملغىً وتُعاد الفواتير والأرصدة. لا يمكن التراجع.')
                    ->form([
                        Textarea::make('notes')
                            ->label('ملاحظات الاعتماد (اختياري)')
                            ->rows(2)
                            ->maxLength(1000),
                    ])
                    ->action(fn (Payment $record, array $data) => self::decide($record, true, $data['notes'] ?? null)),

                Action::make('reject_void')
                    ->label('رفض الطلب')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('رفض طلب الإلغاء')
                    ->modalDescription('سيبقى السند صالحاً ويُبلَّغ المحصّل بالسبب.')
                    ->form([
                        Textarea::make('notes')
                            ->label('سبب الرفض')
                            ->rows(2)
                            ->required()
                            ->rules(['min:10'])
                            ->maxLength(1000),
                    ])
                    ->action(fn (Payment $record, array $data) => self::decide($record, false, $data['notes'] ?? null)),
            ])
            ->toolbarActions([]);
    }

    private static function decide(Payment $record, bool $approve, ?string $notes): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        try {
            app(PaymentService::class)->decideVoidRequest($record, $approve, $user, $notes, 'web');

            Notification::make()
                ->title($approve ? 'تم اعتماد الإلغاء' : 'تم رفض طلب الإلغاء')
                ->success()
                ->send();
        } catch (Throwable $error) {
            Notification::make()
                ->title('تعذر تنفيذ القرار')
                ->body($error->getMessage())
                ->danger()
                ->send();
        }
    }
}

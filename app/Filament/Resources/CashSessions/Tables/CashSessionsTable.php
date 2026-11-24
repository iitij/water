<?php

namespace App\Filament\Resources\CashSessions\Tables;

use App\Models\CashSession;
use App\Services\CashSessionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Throwable;

class CashSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('المحصّل')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('session_date')
                    ->label('تاريخ الصندوق')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('square.name')
                    ->label('المربع')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('state')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        CashSession::STATE_OPEN => 'مفتوح',
                        CashSession::STATE_CLOSED => 'بانتظار الاعتماد',
                        CashSession::STATE_APPROVED => 'معتمد',
                        CashSession::STATE_REJECTED => 'مرفوض',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        CashSession::STATE_OPEN => 'info',
                        CashSession::STATE_CLOSED => 'warning',
                        CashSession::STATE_APPROVED => 'success',
                        CashSession::STATE_REJECTED => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('expected_amount')
                    ->label('المتوقع')
                    ->numeric(2)
                    ->sortable(),
                Tables\Columns\TextColumn::make('counted_amount')
                    ->label('المعدود')
                    ->numeric(2)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('variance_amount')
                    ->label('الفرق')
                    ->numeric(2)
                    ->placeholder('—')
                    ->color(fn (?string $state): string => match (true) {
                        $state === null => 'gray',
                        abs((float) $state) < 0.01 => 'success',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('closing_notes')
                    ->label('ملاحظات الإغلاق')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('closed_at')
                    ->label('أُغلق في')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('approver.name')
                    ->label('اعتمده')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('state')
                    ->label('الحالة')
                    ->options([
                        CashSession::STATE_OPEN => 'مفتوح',
                        CashSession::STATE_CLOSED => 'بانتظار الاعتماد',
                        CashSession::STATE_APPROVED => 'معتمد',
                        CashSession::STATE_REJECTED => 'مرفوض',
                    ]),
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('المحصّل')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                Action::make('correct_duplicate')
                    ->label('تصحيح سند مكرر')
                    ->color('warning')
                    ->visible(function (CashSession $record): bool {
                        $user = Auth::user();
                        return $record->isApproved() && $user
                            && (int) $record->user_id !== (int) $user->id
                            && ($user->hasRole('super_admin') ||
                                ($user->can('cash_sessions.approve') && $user->can('payments.void.approve')));
                    })
                    ->form([
                        \Filament\Forms\Components\TextInput::make('payment_id')->label('معرّف السند المكرر')->integer()->required(),
                        \Filament\Forms\Components\TextInput::make('duplicate_of_id')->label('معرّف السند الأصلي الصحيح')->integer()->required(),
                        Textarea::make('reason')->label('سبب التصحيح')->required()->minLength(10)->maxLength(1000),
                        Textarea::make('evidence')->label('دليل عدم استلام مبلغ إضافي')->required()->minLength(10)->maxLength(2000),
                        \Filament\Forms\Components\Checkbox::make('no_second_cash_receipt')
                            ->label('أؤكد عدم استلام دفعة ثانية فعلياً')->accepted()->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (CashSession $record, array $data): void {
                        abort_unless($data['no_second_cash_receipt'] ?? false, 422);
                        $payment = \App\Models\Payment::where('cash_session_id', $record->id)->findOrFail($data['payment_id']);
                        app(\App\Services\ClosedCashCorrectionService::class)->correctDuplicate(
                            $payment, (int) $data['duplicate_of_id'], Auth::user(), $data['reason'], $data['evidence']
                        );
                        Notification::make()->title('تم التصحيح دون تغيير التسوية الأصلية')->success()->send();
                    }),
                Action::make('approve')
                    ->label('اعتماد')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('اعتماد صندوق المحصّل')
                    ->modalDescription('سيُثبَّت الفرق المحسوب ويصبح الصندوق مقفلاً نهائياً.')
                    ->visible(fn (CashSession $record): bool => self::canDecide($record))
                    ->form([
                        Textarea::make('notes')
                            ->label('ملاحظات الاعتماد (اختياري)')
                            ->rows(2)
                            ->maxLength(1000),
                    ])
                    ->action(function (CashSession $record, array $data): void {
                        self::decide($record, true, $data['notes'] ?? null);
                    }),

                Action::make('reject')
                    ->label('رفض')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('رفض صندوق المحصّل')
                    ->modalDescription('يُعاد الصندوق للمحصّل مع سبب الرفض ليُصحّح الفرق.')
                    ->visible(fn (CashSession $record): bool => self::canDecide($record))
                    ->form([
                        Textarea::make('notes')
                            ->label('سبب الرفض')
                            ->rows(2)
                            ->required()
                            ->rules(['min:10'])
                            ->maxLength(1000),
                    ])
                    ->action(function (CashSession $record, array $data): void {
                        self::decide($record, false, $data['notes'] ?? null);
                    }),
            ])
            // لا حذف جماعي ولا إجراءات كتلية: الصندوق أثر مالي لا يُمحى.
            ->toolbarActions([]);
    }

    private static function canDecide(CashSession $record): bool
    {
        if (! $record->isClosed()) {
            return false;
        }

        $user = Auth::user();
        if ($user === null) {
            return false;
        }

        return $user->isAdmin()
            || $user->can('cash_sessions.approve')
            || $user->can('Approve:CashSession');
    }

    private static function decide(CashSession $record, bool $approve, ?string $notes): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        try {
            app(CashSessionService::class)->decide($record, $approve, $user, $notes);

            Notification::make()
                ->title($approve ? 'تم اعتماد الصندوق' : 'تم رفض الصندوق')
                ->success()
                ->send();
        } catch (Throwable $error) {
            Notification::make()
                ->title('تعذر تنفيذ العملية')
                ->body($error->getMessage())
                ->danger()
                ->send();
        }
    }
}

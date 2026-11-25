<?php

namespace App\Filament\Resources\CashSessions\Tables;

use App\Models\CashSession;
use App\Services\CashSessionService;
use App\Services\ProspectiveSettlementEntry;
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
                    ->label('المتوقع للتسوية')
                    ->getStateUsing(fn (CashSession $record): float => app(CashSessionService::class)->expectedAmount($record))
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
                Tables\Columns\TextColumn::make('treasury_handover')
                    ->label('التسليم للخزينة')
                    ->getStateUsing(fn (CashSession $record): string => match (app(\App\Services\CashHandoverService::class)->summary($record)['status']) {
                        'not_eligible' => 'غير قابل للتسوية',
                        'not_required' => 'لا نقد معدود',
                        'pending' => 'لم يوثق التسليم',
                        'partial' => 'تسليم جزئي',
                        'complete' => 'اكتمل التسليم',
                        default => 'خلل يتطلب المراجعة',
                    })
                    ->badge(),
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
                Action::make('record_treasury_handover')
                    ->label('توثيق استلام الخزينة')
                    ->icon('heroicon-o-banknotes')
                    ->visible(function (CashSession $record): bool {
                        $user = Auth::user();
                        $service = app(\App\Services\CashHandoverService::class);
                        return $user && $service->canRecord($user)
                            && (int) $record->user_id !== (int) $user->id
                            && in_array($service->summary($record)['status'], ['pending', 'partial'], true);
                    })
                    ->form([
                        \Filament\Forms\Components\TextInput::make('amount')
                            ->label('النقد المستلم فعلياً')->numeric()->minValue(0.01)->required()
                            ->helperText(fn (CashSession $record): string => 'المتبقي للتسليم: '.(app(\App\Services\CashHandoverService::class)->summary($record)['remaining_amount'] ?? '—')),
                        \Filament\Forms\Components\TextInput::make('reference')
                            ->label('مرجع سند التسليم')->required()->maxLength(150),
                        \Filament\Forms\Components\DatePicker::make('received_on')
                            ->label('تاريخ الاستلام الفعلي')->default(now())->maxDate(now())->required(),
                        Textarea::make('evidence')->label('دليل الاستلام الفعلي')->required()->minLength(10)->maxLength(2000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (CashSession $record, array $data): void {
                        app(\App\Services\CashHandoverService::class)->record($record, Auth::user(),
                            $data['amount'], $data['reference'], $data['received_on'], $data['evidence']);
                        Notification::make()->title('تم توثيق الاستلام الفعلي')->success()->send();
                    }),
                Action::make('late_receipt_review')
                    ->label('مراجعة سند مؤجل')
                    ->color('warning')
                    ->visible(function (CashSession $record): bool {
                        $user = Auth::user();
                        return ! $record->isOpen() && $user
                            && (int) $record->user_id !== (int) $user->id
                            && app(\App\Services\LateReceiptReviewService::class)->canDecide($user)
                            && \Illuminate\Support\Facades\DB::table('late_receipt_reviews')
                                ->where('cash_session_id', $record->id)->where('status', 'pending')->exists();
                    })
                    ->form([
                        \Filament\Forms\Components\Select::make('review_id')
                            ->label('المطالبة المعلقة (المشترك، المبلغ، الطريقة، التاريخ)')
                            ->options(fn (CashSession $record): array => \Illuminate\Support\Facades\DB::table('late_receipt_reviews')
                                ->join('subscribers', 'subscribers.id', '=', 'late_receipt_reviews.subscriber_id')
                                ->where('cash_session_id', $record->id)->where('late_receipt_reviews.status', 'pending')
                                ->select('late_receipt_reviews.*', 'subscribers.name as subscriber_name')
                                ->orderBy('late_receipt_reviews.id')->get()
                                ->mapWithKeys(fn ($row) => [$row->id => '#'.$row->id.' | '.$row->subscriber_name.' | '.$row->amount.' | '.$row->payment_method.' | '.$row->payment_date])->all())
                            ->searchable()->required(),
                        \Filament\Forms\Components\Radio::make('decision')
                            ->label('القرار')->options(['approve' => 'اعتماد', 'reject' => 'رفض'])->required(),
                        \Filament\Forms\Components\Select::make('settlement_method')
                            ->label('طريقة الإثبات عند الاعتماد')
                            ->helperText(fn (CashSession $record): string => 'الفائض الأصلي غير المخصص: '.number_format(app(\App\Services\LateReceiptReviewService::class)->countedCashAllocation($record)['available_minor'] / 100, 2, '.', ''))
                            ->options(['separate_handover' => 'تسليم نقدي مستقل خارج المبلغ المعدود', 'verified_non_cash' => 'إثبات تحصيل غير نقدي', 'already_counted' => 'ضمن فائض النقد المعدود المثبت']),
                        \Filament\Forms\Components\TextInput::make('evidence_reference')
                            ->label('مرجع التسوية المستقل عند الاعتماد')->maxLength(150),
                        Textarea::make('evidence')->label('الدليل وسبب القرار')->required()->minLength(10)->maxLength(2000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (CashSession $record, array $data): void {
                        $review = \Illuminate\Support\Facades\DB::table('late_receipt_reviews')
                            ->where('cash_session_id', $record->id)->find((int) $data['review_id']);
                        abort_unless($review, 404);
                        try {
                            app(ProspectiveSettlementEntry::class)->decide(Auth::user(), (int) $review->id, [
                                'approve' => $data['decision'] === 'approve',
                                'evidence' => $data['evidence'],
                                'evidence_reference' => $data['evidence_reference'] ?? null,
                                'settlement_method' => $data['settlement_method'] ?? null,
                            ]);
                        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $error) {
                            // Preserve the mounted review and evidence; never fall back.
                            throw \Illuminate\Validation\ValidationException::withMessages(['data' => $error->getMessage()]);
                        }
                        Notification::make()->title('تم تسجيل قرار المراجعة')->success()->send();
                    }),
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

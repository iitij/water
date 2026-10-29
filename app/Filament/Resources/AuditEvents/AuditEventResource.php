<?php

namespace App\Filament\Resources\AuditEvents;

use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Models\AuditEvent;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * سجل التدقيق (خطة 2.0 — الفصل 9.6).
 *
 * للقراءة فقط: لا إنشاء ولا تعديل ولا حذف من اللوحة، لأن السجل دليل محاسبي
 * يجب أن يبقى غير قابل للتغيير. الوصول محصور بالمشرفين.
 */
class AuditEventResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $modelLabel = 'حدث تدقيق';

    protected static ?string $pluralModelLabel = 'سجل التدقيق';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة النظام';

    protected static ?int $navigationSort = 90;

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user !== null && ($user->isAdmin() || $user->can('audit.view'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')
                    ->label('الوقت')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('event')
                    ->label('الحدث')
                    ->searchable()
                    ->badge()
                    ->color(fn (string $state): string => str_contains($state, 'voided')
                        || str_contains($state, 'rejected')
                            ? 'danger'
                            : 'gray'),
                Tables\Columns\TextColumn::make('actor.name')
                    ->label('المستخدم')
                    ->searchable()
                    ->placeholder('نظام'),
                Tables\Columns\TextColumn::make('entity_type')
                    ->label('الكيان')
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : class_basename($state))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('entity_id')
                    ->label('رقم السجل')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('source')
                    ->label('المصدر')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'mobile' => 'التطبيق',
                        'api' => 'واجهة API',
                        'web' => 'لوحة الإدارة',
                        'console' => 'مهام النظام',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('device_id')
                    ->label('الجهاز')
                    ->limit(12)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->label('نوع الحدث')
                    ->options([
                        'payment.created' => 'إنشاء سند قبض',
                        'payment.voided' => 'إلغاء سند قبض',
                        'cash_session.opened' => 'فتح صندوق',
                        'cash_session.closed' => 'إغلاق صندوق',
                        'cash_session.approved' => 'اعتماد صندوق',
                        'cash_session.rejected' => 'رفض صندوق',
                        'auth.login' => 'تسجيل دخول',
                        'auth.token_refreshed' => 'تجديد جلسة',
                        'device.registered' => 'تسجيل جهاز',
                        'device.revoked' => 'إبطال جهاز',
                    ])
                    ->searchable(),
                Tables\Filters\SelectFilter::make('source')
                    ->label('المصدر')
                    ->options([
                        'mobile' => 'التطبيق',
                        'api' => 'واجهة API',
                        'web' => 'لوحة الإدارة',
                        'console' => 'مهام النظام',
                    ]),
                Tables\Filters\SelectFilter::make('actor_user_id')
                    ->label('المستخدم')
                    ->relationship('actor', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEvents::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}

<?php

namespace App\Filament\Resources\CashSessions;

use App\Filament\Resources\CashSessions\Pages\ListCashSessions;
use App\Filament\Resources\CashSessions\Tables\CashSessionsTable;
use App\Models\CashSession;
use Filament\Resources\Resource;
use Filament\Tables\Table;

/**
 * اعتماد صناديق المحصّلين (خطة 2.0 — الفصل 5.2).
 *
 * الصفحة للعرض والاعتماد فقط: لا إنشاء ولا تعديل ولا حذف من لوحة الإدارة،
 * لأن الصندوق يُفتح ويُغلق من الميدان فقط، والحذف ليس مساراً معتمداً أبداً.
 */
class CashSessionResource extends Resource
{
    protected static ?string $model = CashSession::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $modelLabel = 'صندوق محصّل';

    protected static ?string $pluralModelLabel = 'صناديق المحصّلين';

    protected static string|\UnitEnum|null $navigationGroup = 'العمليات';

    protected static ?int $navigationSort = 4;

    /** عدّاد الصناديق المنتظرة للاعتماد. */
    public static function getNavigationBadge(): ?string
    {
        $pending = CashSession::query()->pendingApproval()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return CashSessionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashSessions::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}

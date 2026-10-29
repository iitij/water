<?php

namespace App\Filament\Resources\VoidRequests;

use App\Filament\Resources\VoidRequests\Pages\ListVoidRequests;
use App\Models\Payment;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * اعتماد إلغاءات السندات الكبيرة (خطة 2.0 — الفصل 5.3).
 *
 * السند الذي يتجاوز حد الإلغاء يبقى صالحاً حتى يقرر المشرف. هذه الشاشة لا
 * تحذف شيئاً: الاعتماد يمرّ عبر `PaymentService::decideVoidRequest`.
 */
class VoidRequestResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $modelLabel = 'طلب إلغاء';

    protected static ?string $pluralModelLabel = 'اعتماد إلغاء السندات';

    protected static string|\UnitEnum|null $navigationGroup = 'العمليات';

    protected static ?int $navigationSort = 5;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->voidRequested();
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Payment::query()->voidRequested()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user !== null && (
            $user->isAdmin()
            || $user->can('payments.void.approve')
            || $user->can('Approve:Payment')
        );
    }

    public static function table(Table $table): Table
    {
        return \App\Filament\Resources\VoidRequests\Tables\VoidRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVoidRequests::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}

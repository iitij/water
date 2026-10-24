<?php

namespace App\Filament\Resources\CashSessions\Pages;

use App\Filament\Resources\CashSessions\CashSessionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * قائمة صناديق المحصّلين — صفحة قراءة واعتماد فقط، بلا إنشاء.
 */
class ListCashSessions extends ListRecords
{
    protected static string $resource = CashSessionResource::class;

    public function getTitle(): string
    {
        return 'صناديق المحصّلين';
    }

    public function getSubheading(): ?string
    {
        return 'الصناديق المغلقة تنتظر اعتماد المشرف. اعتماد الصندوق يثبّت الفرق النقدي ولا يمكن التراجع عنه من الميدان.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}

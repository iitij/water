<?php

namespace App\Filament\Resources\AuditEvents\Pages;

use App\Filament\Resources\AuditEvents\AuditEventResource;
use Filament\Resources\Pages\ListRecords;

/**
 * سجل التدقيق — عرض فقط، بلا إنشاء أو تعديل.
 */
class ListAuditEvents extends ListRecords
{
    protected static string $resource = AuditEventResource::class;

    public function getTitle(): string
    {
        return 'سجل التدقيق';
    }

    public function getSubheading(): ?string
    {
        return 'كل عملية حسّاسة: من نفّذها، من أي جهاز، وماذا تغيّر قبل وبعد. السجل غير قابل للتعديل.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}

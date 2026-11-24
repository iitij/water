<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Models\Subscriber;
use App\Services\InvoiceService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $subscriber = Subscriber::findOrFail($data['subscriber_id']);

        return app(InvoiceService::class)->generateInvoice(
            subscriber: $subscriber,
            notes: $data['notes'] ?? null,
        );
    }
}

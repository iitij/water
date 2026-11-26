<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Models\Subscriber;
use App\Filament\Actions\ProspectiveInvoiceAction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $subscriber = Subscriber::findOrFail($data['subscriber_id']);

        return ProspectiveInvoiceAction::issue($subscriber, null, $data);
    }
}

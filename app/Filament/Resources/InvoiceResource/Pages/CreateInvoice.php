<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = 'issued';
        return $data;
    }

    protected function afterCreate(): void
    {
        $invoice = $this->record;
        
        $transactionService = new \App\Services\TransactionService();
        $transactionService->recordTransaction(
            subscriber: $invoice->subscriber,
            type: 'invoice',
            amount: -$invoice->total_amount,
            description: 'إصدار فاتورة رقم ' . $invoice->bill_number,
            reference: $invoice,
            meter: $invoice->meter
        );

        if ($invoice->meter) {
            $transactionService->updateMeterBalance($invoice->meter);
        } else {
            $transactionService->updateSubscriberBalance($invoice->subscriber);
        }
    }
}






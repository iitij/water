<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $subscriber = \App\Models\Subscriber::findOrFail($data['subscriber_id']);
        $consumption = (float) ($data['consumption_fee'] ?? 0);
        // Invoices are now exclusively for water consumption.
        $meter = app(\App\Services\CurrentMeterBillingGuard::class)->resolve($subscriber);
        $data['meter_id'] = $meter->id;
        $data['invoice_type'] = 'meter';
        $data['status'] = 'issued';
        $data['total_amount'] = $consumption;
        $data['previous_balance'] = $subscriber->invoices()->where('status', '!=', 'draft')->get()->sum(fn ($invoice) => $invoice->getRemainingAmount());
        $data['credit_applied'] = min($subscriber->getAccountCredit(), $consumption);
        $data['amount_paid'] = 0;

        return $data;
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            \App\Models\Subscriber::query()->lockForUpdate()->findOrFail($data['subscriber_id']);
            $meter = \App\Models\Meter::query()->where('subscriber_id', $data['subscriber_id'])
                ->lockForUpdate()->findOrFail($data['meter_id']);
            $data = $this->mutateFormDataBeforeCreate($data);
            abort_unless((int) $data['meter_id'] === (int) $meter->id, 409, 'تغير العداد؛ حدّث النموذج.');
            $data['bill_number'] = \App\Models\Invoice::generateBillNumber();
            $invoice = \App\Models\Invoice::create($data);
            app(\App\Services\TransactionService::class)->recordTransaction(
                subscriber: $invoice->subscriber,
                type: 'invoice',
                amount: -(float) $invoice->total_amount,
                description: 'إصدار فاتورة رقم '.$invoice->bill_number,
                reference: $invoice,
                meter: $meter,
            );
            return $invoice->refresh();
        }, 3);
    }
}

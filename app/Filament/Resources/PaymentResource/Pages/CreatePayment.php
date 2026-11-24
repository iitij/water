<?php
namespace App\Filament\Resources\PaymentResource\Pages;
use App\Filament\Resources\PaymentResource;
use Filament\Resources\Pages\CreateRecord;
class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $subscriber = \App\Models\Subscriber::find($data['subscriber_id']);
        $service = new \App\Services\PaymentService();

        return $service->collectPayment(
            $subscriber,
            $data['amount'],
            $data['invoice_id'] ?? null,
            $data['payment_method'] ?? 'cash',
            $data['reference_number'] ?? null,
            $data['notes'] ?? null,
            $data['reason'] ?? null,
            \Illuminate\Support\Facades\Auth::id(),
            $data['payment_date'] ?? null
        );
    }
}






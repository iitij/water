<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Resources\PaymentResource;
use App\Models\Subscriber;
use App\Services\PaymentService;
use App\Services\ProspectiveCashReceiptEntry;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        Gate::authorize('create', \App\Models\Payment::class);
        $subscriber = Subscriber::findOrFail($data['subscriber_id']);
        $entry = app(ProspectiveCashReceiptEntry::class);
        if ($entry->enabled()) {
            try {
                return $entry->collect(auth()->user(), $subscriber, $data);
            } catch (ConflictHttpException $error) {
                // Keep the form and its original UUID; no legacy fallback.
                throw ValidationException::withMessages(['data.amount' => $error->getMessage()]);
            }
        }
        return app(PaymentService::class)->collectPayment(
            $subscriber,
            $data['amount'],
            $data['invoice_id'] ?? null,
            $data['payment_method'] ?? 'cash',
            $data['reference_number'] ?? null,
            $data['notes'] ?? null,
            $data['reason'] ?? null,
            auth()->id(),
            $data['payment_date'] ?? null,
        );
    }
}

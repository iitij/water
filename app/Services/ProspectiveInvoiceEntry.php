<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Subscriber;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ProspectiveInvoiceEntry
{
    public function enabled(): bool
    {
        if (config('financial_candidate.invoices') !== true) return false;
        abort_unless(app()->environment(['testing', 'staging']), 503);
        return true;
    }

    public function issue(User $actor, Subscriber $subscriber, ?Meter $meter, ?string $notes, ?string $uuid): Invoice
    {
        if (! $actor->hasRole('super_admin')) Gate::forUser($actor)->authorize('create', Invoice::class);
        try {
            $meter = app(CurrentMeterBillingGuard::class)->resolve($subscriber, $meter);
            if ($this->enabled()) {
                Validator::make(['operation_uuid' => $uuid], ['operation_uuid' => ['required', 'uuid']])->validate();
                return app(GatedInvoiceTransferWriter::class)->issue($actor, (int) $subscriber->id,
                    (int) $meter->id, strtolower($uuid), now()->toDateTimeImmutable(), $notes)['invoice'];
            }
            if ($uuid !== null) throw new DomainException('gated_invoice_entry_disabled');
            return app(InvoiceService::class)->generateInvoice($subscriber, $meter, $notes);
        } catch (DomainException $error) {
            throw new ConflictHttpException($error->getMessage(), $error);
        }
    }

    public function cancel(User $actor, Invoice $invoice, string $reason, ?string $uuid, string $source): Invoice
    {
        if (! $actor->hasRole('super_admin')) Gate::forUser($actor)->authorize('delete', $invoice);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:10', 'max:1000']])->validate();
        try {
            if ($this->enabled()) {
                Validator::make(['operation_uuid' => $uuid], ['operation_uuid' => ['required', 'uuid']])->validate();
                return app(GatedInvoiceTransferWriter::class)->cancel($actor, (int) $invoice->id,
                    $reason, strtolower($uuid), now()->toDateTimeImmutable())['invoice'];
            }
            if ($uuid !== null) throw new DomainException('gated_invoice_entry_disabled');
            return app(InvoiceCancellationService::class)->cancel($invoice, $reason, (int) $actor->id, $source);
        } catch (DomainException $error) {
            throw new ConflictHttpException($error->getMessage(), $error);
        }
    }
}

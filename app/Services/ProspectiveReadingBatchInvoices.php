<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Reading;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Each meter is a separate financial operation; partial successes can be replayed. */
final class ProspectiveReadingBatchInvoices
{
    public function issue(User $actor, array $ids, ?string $notes, ?string $batchUuid): array
    {
        if (! $actor->hasRole('super_admin')) Gate::forUser($actor)->authorize('create', Invoice::class);
        $entry = app(ProspectiveInvoiceEntry::class);
        $enabled = $entry->enabled();
        if ($enabled) {
            Validator::make(['operation_uuid' => $batchUuid], ['operation_uuid' => ['required', 'uuid']])->validate();
        } elseif ($batchUuid !== null) {
            throw new ConflictHttpException('gated_invoice_entry_disabled');
        }

        $ids = Validator::make(['ids' => $ids], [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct', 'exists:readings,id'],
        ])->validate()['ids'];
        $query = Reading::with('meter.subscriber')->whereIn('id', $ids)->orderBy('id');
        if (! $enabled) $query->where('is_invoiced', false);
        $readings = $query->get();
        if ($enabled && $readings->count() !== count($ids)) {
            throw new ConflictHttpException('gated_invoice_reading_selection');
        }

        $invoices = [];
        $errors = [];
        foreach ($readings->groupBy('meter_id')->sortKeys() as $meterId => $group) {
            $meter = $group->first()->meter;
            if (! $meter?->subscriber) {
                $errors[] = ['meter_id' => (int) $meterId, 'message' => 'تعذر العثور على المشترك المرتبط بالعداد.'];
                continue;
            }
            try {
                $uuid = $enabled ? Uuid::uuid5(strtolower($batchUuid), 'water:reading-batch:meter:'.$meterId)->toString() : null;
                $selected = $enabled ? $group->modelKeys() : null;
                $invoices[] = $entry->issue($actor, $meter->subscriber, $meter, $notes, $uuid, $selected)
                    ->load(['subscriber', 'meter', 'items']);
            } catch (\Throwable $error) {
                $errors[] = ['meter_id' => (int) $meterId, 'message' => $error->getMessage()];
            }
        }
        return ['invoices_issued' => count($invoices), 'invoices' => $invoices, 'errors' => $errors];
    }
}

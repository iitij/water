<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Subscriber;
use App\Services\ProspectiveInvoiceEntry;
use Filament\Forms\Components\Hidden;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ProspectiveInvoiceAction
{
    public static function identity(): Hidden
    {
        return Hidden::make('operation_uuid')
            ->default(fn () => (string) Str::uuid())
            ->dehydrated(fn () => app(ProspectiveInvoiceEntry::class)->enabled())
            ->required(fn () => app(ProspectiveInvoiceEntry::class)->enabled())
            ->rules(['nullable', 'uuid']);
    }

    public static function issue(Subscriber $subscriber, ?Meter $meter, array $data): Invoice
    {
        Gate::authorize('create', Invoice::class);
        try {
            return app(ProspectiveInvoiceEntry::class)->issue(auth()->user(), $subscriber, $meter,
                $data['notes'] ?? null, $data['operation_uuid'] ?? null);
        } catch (ConflictHttpException $error) {
            throw ValidationException::withMessages(['data' => $error->getMessage()]);
        }
    }

    public static function cancel(Invoice $invoice, array $data): Invoice
    {
        Gate::authorize('delete', $invoice);
        try {
            return app(ProspectiveInvoiceEntry::class)->cancel(auth()->user(), $invoice, $data['reason'],
                $data['operation_uuid'] ?? null, 'web');
        } catch (ConflictHttpException $error) {
            throw ValidationException::withMessages(['data' => $error->getMessage()]);
        }
    }
}

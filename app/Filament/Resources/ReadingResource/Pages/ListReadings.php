<?php

namespace App\Filament\Resources\ReadingResource\Pages;

use App\Filament\Resources\ReadingResource;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\ProspectiveReadingAutoInvoice;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ListReadings extends ListRecords
{
    protected static string $resource = ReadingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['created_by'] = auth()->id();
                    return $data;
                })
                ->using(function (array $data): Reading {
                    Gate::authorize('create', Reading::class);
                    $actor = auth()->user();
                    return DB::transaction(function () use ($data, $actor): Reading {
                        app(\App\Services\ReadingObservationFence::class)->lock();
                        $meter = Meter::findOrFail($data['meter_id']);
                        Subscriber::query()->lockForUpdate()->findOrFail($meter->subscriber_id);
                        $locked = Meter::query()->lockForUpdate()->findOrFail($meter->id);
                        abort_unless((int) $locked->subscriber_id === (int) $meter->subscriber_id, 409);
                        $data['created_by'] = $actor->id;
                        $reading = Reading::create($data);
                        app(ProspectiveReadingAutoInvoice::class)->capture($reading, $actor, null);
                        return $reading;
                    }, 3);
                })
                ->after(function (Reading $record) {
                    $actorId = (int) auth()->id();
                    $readingId = (int) $record->id;
                    $issue = static function () use ($actorId, $readingId): void {
                        try {
                            $reading = Reading::with('meter.subscriber')->findOrFail($readingId);
                            $auto = app(ProspectiveReadingAutoInvoice::class);
                            if (config('financial_candidate.invoices') === true || $auto->hasIntent($reading)) {
                                $invoice = $auto->attempt($reading, User::findOrFail($actorId), null);
                            } elseif (\App\Models\Setting::get('auto_issue_invoice_on_reading', '0') == '1') {
                                $invoice = app(\App\Services\InvoiceService::class)->generateInvoice(
                                    $reading->meter->subscriber, $reading->meter);
                            } else {
                                return;
                            }
                            if ($invoice !== null && $invoice->status !== 'cancelled') {
                                Notification::make()->title('تم إصدار الفاتورة تلقائياً')->success()->send();
                            }
                        } catch (\Throwable $error) {
                            Notification::make()->title('تم حفظ القراءة، وتعذر إصدار الفاتورة تلقائياً')
                                ->body($error->getMessage())->danger()->send();
                        }
                    };
                    // FinancialWriteGate must start at transaction level zero.
                    if (DB::transactionLevel() > 0) {
                        DB::afterCommit($issue);
                    } else {
                        $issue();
                    }
                }),
        ];
    }
}

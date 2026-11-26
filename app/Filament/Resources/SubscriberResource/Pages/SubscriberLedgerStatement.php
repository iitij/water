<?php

namespace App\Filament\Resources\SubscriberResource\Pages;

use App\Filament\Resources\SubscriberResource;
use App\Services\LedgerStatementService;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

final class SubscriberLedgerStatement extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SubscriberResource::class;
    protected string $view = 'filament.resources.subscribers.ledger-statement';
    protected static ?string $title = 'كشف حساب المشترك';

    public string $fromDate = '';
    public string $toDate = '';
    #[Locked]
    public ?array $statement = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        Gate::authorize('view', $this->record);
        $this->fromDate = now()->startOfMonth()->toDateString();
        $this->toDate = now()->toDateString();
        try {
            $this->loadStatement();
        } catch (\Illuminate\Validation\ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) $this->addError($field, $message);
            }
        }
    }

    public function hydrate(): void
    {
        Gate::authorize('view', $this->record);
    }

    public function updatedFromDate(): void
    {
        $this->statement = null;
    }

    public function updatedToDate(): void
    {
        $this->statement = null;
    }

    public function loadStatement(): void
    {
        Gate::authorize('view', $this->record);
        $this->statement = null;
        $period = $this->validate([
            'fromDate' => ['required', 'date_format:Y-m-d'],
            'toDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:fromDate', 'before_or_equal:today'],
        ]);
        $this->statement = app(LedgerStatementService::class)->statement($this->record, $period['fromDate'], $period['toDate']);
    }
}

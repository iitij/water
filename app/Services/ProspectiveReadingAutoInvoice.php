<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Invoice;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Durable automatic issuance intent; the observation commits before the financial gate. */
final class ProspectiveReadingAutoInvoice
{
    private const EVENT = 'financial.reading_auto_invoice_intent';

    private function allowed(User $actor): bool
    {
        return $actor->hasRole('super_admin') || $actor->can('Create:Invoice');
    }

    private function configured(): bool
    {
        return filter_var(Setting::get('auto_issue_invoice_on_reading', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    private function pending(int $meterId): array
    {
        return Reading::query()->where('meter_id', $meterId)->where('is_invoiced', false)
            ->orderBy('id')->lockForUpdate()->get()->map(fn (Reading $row) => [
                (int) $row->id, (string) $row->current_reading, (string) $row->previous_reading,
                (string) $row->reading_date?->toDateString(), (string) $row->actual_reading_date?->toDateString(),
                (string) $row->period_start, (string) $row->period_end, $row->period_basis,
            ])->all();
    }

    private function intent(int $readingId, bool $lock = false): ?AuditEvent
    {
        $query = AuditEvent::query()->where('event', self::EVENT)->where('entity_type', Reading::class)
            ->where('entity_id', (string) $readingId);
        if ($lock) $query->lockForUpdate();
        $rows = $query->get();
        if ($rows->count() > 1) throw new DomainException('gated_auto_invoice_intent_conflict');
        return $rows->first();
    }

    /** Caller holds subscriber and meter locks in the observation creation transaction. */
    public function capture(Reading $reading, User $actor, ?string $notes): void
    {
        if (config('financial_candidate.invoices') !== true) return;
        if (DB::transactionLevel() === 0 || (int) $reading->created_by !== (int) $actor->id || $this->intent((int) $reading->id, true)) {
            throw new DomainException('gated_auto_invoice_intent_conflict');
        }
        $configured = $this->configured();
        $eligible = $configured && $this->allowed($actor);
        $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, 'water:reading:auto:'.$reading->id.':'.$reading->sync_uuid)->toString();
        app(AuditLogger::class)->recordRequired(self::EVENT, [
            'entity_type' => Reading::class, 'entity_id' => $reading->id, 'actor_user_id' => $actor->id,
            'after' => ['meter_id' => (int) $reading->meter_id, 'operation_uuid' => $uuid, 'notes' => $notes,
                'configured' => $configured, 'eligible' => $eligible,
                'pending' => $eligible ? $this->pending((int) $reading->meter_id) : []],
        ]);
    }

    public function hasIntent(Reading $reading): bool
    {
        return $this->intent((int) $reading->id) !== null;
    }

    /** Only the original authorized actor can resume an unposted null-notes intent from the web. */
    public function canResume(Reading $reading, User $actor): bool
    {
        if (config('financial_candidate.invoices') !== true || $reading->is_invoiced
            || (int) $reading->created_by !== (int) $actor->id || ! $this->allowed($actor)) return false;
        $audit = $this->intent((int) $reading->id);
        $data = $audit?->after;
        return $audit !== null && (int) $audit->actor_user_id === (int) $actor->id
            && ($data['eligible'] ?? null) === true && ($data['configured'] ?? null) === true
            && array_key_exists('notes', $data) && $data['notes'] === null
            && is_string($data['operation_uuid'] ?? null)
            && ! DB::table('financial_posting_operations')->where('operation_uuid', $data['operation_uuid'])->exists();
    }

    /** Never infer a new intent for a previously saved reading. */
    public function attempt(Reading $reading, User $actor, ?string $notes): ?Invoice
    {
        $audit = $this->intent((int) $reading->id);
        if ($audit === null) return null;
        $data = $audit->after;
        if ((int) $audit->actor_user_id !== (int) $actor->id || ($data['notes'] ?? null) !== $notes
            || (int) ($data['meter_id'] ?? 0) !== (int) $reading->meter_id) {
            throw new DomainException('gated_auto_invoice_intent_conflict');
        }
        if (($data['configured'] ?? null) === false) return null;
        if (($data['eligible'] ?? null) !== true) throw new DomainException('gated_auto_invoice_not_authorized_at_creation');
        if (! app(ProspectiveInvoiceEntry::class)->enabled()) throw new DomainException('gated_invoice_entry_disabled');
        $meter = $reading->meter;
        if (! $meter || ! $meter->subscriber) throw new DomainException('gated_auto_invoice_meter_missing');
        $ids = array_column($data['pending'], 0);
        return app(GatedInvoiceTransferWriter::class)->issue($actor, (int) $meter->subscriber_id, (int) $meter->id,
            $data['operation_uuid'], now()->toDateTimeImmutable(), $notes, $ids, (int) $reading->id)['invoice'];
    }

    /** Recheck intent and frozen observation set under the financial writer's meter lock. */
    public function assertIntent(User $actor, int $readingId, int $meterId, string $uuid, ?string $notes,
        ?array $selected, bool $replay): void
    {
        $audit = $this->intent($readingId, true);
        $data = $audit?->after;
        $reading = Reading::query()->where('meter_id', $meterId)->lockForUpdate()->find($readingId);
        if (! $audit || ! $reading || (int) $audit->actor_user_id !== (int) $actor->id
            || (int) $reading->created_by !== (int) $actor->id || ($data['eligible'] ?? null) !== true
            || ($data['configured'] ?? null) !== true || ($data['operation_uuid'] ?? null) !== $uuid
            || ($data['notes'] ?? null) !== $notes || ($data['meter_id'] ?? null) !== $meterId
            || ! is_array($data['pending'] ?? null) || array_column($data['pending'], 0) !== $selected
            || ! in_array($readingId, $selected ?? [], true) || ! $this->allowed($actor)) {
            throw new DomainException('gated_auto_invoice_intent_conflict');
        }
        // A completed operation may be read again, but a disabled setting or a changed
        // pending set must not initiate another financial write.
        if (! $replay && (! $this->configured() || $this->pending($meterId) !== $data['pending'])) {
            throw new DomainException('gated_auto_invoice_pending_changed_or_disabled');
        }
    }
}

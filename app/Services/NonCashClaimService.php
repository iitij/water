<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class NonCashClaimService
{
    private function lock(): void
    {
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new \LogicException('Payment issuance lock missing.');
        }
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->hasRole('super_admin') || $actor->can('Create:Payment'), 403);
    }

    public function submit(User $actor, array $data): array
    {
        $this->authorize($actor);
        $reference = mb_strtoupper(trim($data['instrument_reference']));
        $date = $data['instrument_on'];
        if (! Str::isUuid($data['sync_uuid']) || ! in_array($data['payment_method'], ['bank_transfer', 'check'], true)
            || ! preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', (string) $data['amount'], $parts)
            || mb_strlen($reference) < 3 || mb_strlen($reference) > 150
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            || $date > today()->toDateString()) {
            throw ValidationException::withMessages(['claim' => 'بيانات طلب التحصيل غير صالحة.']);
        }
        $minor = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($minor <= 0) throw ValidationException::withMessages(['amount' => 'المبلغ يجب أن يكون موجباً.']);
        $record = [
            'sync_uuid' => strtolower($data['sync_uuid']), 'subscriber_id' => (int) $data['subscriber_id'],
            'invoice_id' => isset($data['invoice_id']) ? (int) $data['invoice_id'] : null,
            'submitted_by' => $actor->id, 'amount_minor' => $minor, 'payment_method' => $data['payment_method'],
            'instrument_reference' => $reference, 'instrument_on' => $date, 'notes' => $data['notes'] ?? null,
        ];
        return app(CollectionEvidenceGate::class)->run($actor, fn (User $current) => $this->authorize($current), function (User $actor) use ($record): array {
            $this->lock();
            Subscriber::query()->lockForUpdate()->findOrFail($record['subscriber_id']);
            $existing = DB::table('noncash_collection_claims')->where('sync_uuid', $record['sync_uuid'])->lockForUpdate()->first();
            if ($existing) {
                foreach ($record as $field => $value) {
                    abort_unless((string) $existing->$field === (string) $value, 409, 'المعرف مرتبط بطلب مختلف.');
                }
                return (array) $existing;
            }
            abort_if(Payment::withTrashed()->where('sync_uuid', $record['sync_uuid'])->exists()
                || DB::table('late_receipt_reviews')->where('sync_uuid', $record['sync_uuid'])->exists()
                || DB::table('noncash_collection_claims')->where('payment_method', $record['payment_method'])
                    ->where('instrument_reference', $record['instrument_reference'])->exists()
                || Payment::withTrashed()->where('payment_method', $record['payment_method'])
                    ->whereRaw('UPPER(TRIM(reference_number)) = ?', [$record['instrument_reference']])->exists(),
                409, 'معرف أو مرجع وسيلة التحصيل مستخدم بالفعل.');
            if ($record['invoice_id'] && ! Invoice::where('subscriber_id', $record['subscriber_id'])
                ->whereNotIn('status', ['draft', 'cancelled'])->where('id', $record['invoice_id'])->exists()) {
                throw ValidationException::withMessages(['invoice_id' => 'الفاتورة غير مؤهلة لهذا الحساب.']);
            }
            $id = DB::table('noncash_collection_claims')->insertGetId($record + ['status' => 'pending',
                'created_at' => now(), 'updated_at' => now()]);
            app(AuditLogger::class)->recordRequired('noncash_claim.submitted', [
                'entity_type' => 'noncash_collection_claim', 'entity_id' => $id, 'actor_user_id' => $actor->id,
                'after' => $record,
            ]);
            return (array) DB::table('noncash_collection_claims')->find($id);
        });
    }

    public function index(User $actor): array
    {
        $this->authorize($actor);
        $query = DB::table('noncash_collection_claims');
        if (! $actor->hasRole('super_admin')) $query->where('submitted_by', $actor->id);
        return ['claims' => $query->orderByDesc('id')->paginate(50)];
    }

    public function reject(User $actor, int $id, string $evidence): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        $evidence = trim($evidence);
        if (mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000) {
            throw ValidationException::withMessages(['evidence' => 'يلزم إثبات سبب الرفض.']);
        }
        return app(CollectionEvidenceGate::class)->run($actor, fn (User $current) => app(TreasuryRegisterService::class)->authorize($current), function (User $actor) use ($id, $evidence): array {
            $this->lock();
            $claim = DB::table('noncash_collection_claims')->where('id', $id)->lockForUpdate()->first();
            abort_unless($claim, 404);
            abort_if((int) $claim->submitted_by === (int) $actor->id, 403);
            if ($claim->status !== 'pending') {
                abort_unless($claim->status === 'rejected' && (int) $claim->decided_by === (int) $actor->id
                    && $claim->decision_evidence === $evidence, 409, 'سبق البت في الطلب.');
                return (array) $claim;
            }
            $decision = ['status' => 'rejected', 'decided_by' => $actor->id, 'decision_evidence' => $evidence,
                'decided_at' => now(), 'updated_at' => now()];
            DB::table('noncash_collection_claims')->where('id', $id)->update($decision);
            app(AuditLogger::class)->recordRequired('noncash_claim.rejected', [
                'entity_type' => 'noncash_collection_claim', 'entity_id' => $id, 'actor_user_id' => $actor->id, 'after' => $decision,
            ]);
            return (array) DB::table('noncash_collection_claims')->find($id);
        });
    }
}

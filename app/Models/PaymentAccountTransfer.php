<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PaymentAccountTransfer extends Model
{
    protected $fillable = ['payment_id', 'from_subscriber_id', 'to_subscriber_id', 'approved_by', 'amount', 'reason', 'evidence'];
    protected $casts = ['amount' => 'decimal:2'];

    public function requiresGatedWriter(): bool
    {
        if (config('financial_candidate.subscriber_transfers') === true) return true;
        $payment = Payment::withTrashed()->find($this->payment_id);
        return ($payment?->hasRegisteredReceipt() ?? false) || ($this->exists && \Illuminate\Support\Facades\DB::table('financial_posting_events')->where('scope_key', 'water')->where('source_type', 'ledger_transaction')->where('leg', 'subscriber')->whereIn('source_id', Transaction::withTrashed()->where('reference_type', self::class)->where('reference_id', $this->id)->where('type', 'payment_transfer_in')->pluck('id')->map(fn ($id) => (string) $id)->all())->exists());
    }

    public function assertGatedWriter(mixed $writer): void
    {
        if ($writer === null && ! $this->requiresGatedWriter()) return;
        if (! ($writer instanceof \App\Services\GatedInvoiceTransferWriter && $writer->allowsTransfer((int) $this->payment_id, (int) $this->to_subscriber_id, (int) $this->approved_by))) {
            throw new \DomainException('gated_transfer_document_writer_required');
        }
    }

    public function save(array $options = [])
    {
        $writer = $options['financial_correction_writer'] ?? null;
        unset($options['financial_correction_writer']);
        return \Illuminate\Support\Facades\DB::transaction(function () use ($options, $writer) {
            if (! \Illuminate\Support\Facades\DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Financial issuance lock is missing.');
            }
            if ($this->exists) {
                $persisted = self::query()->lockForUpdate()->findOrFail($this->getRawOriginal($this->getKeyName()));
                if ($writer !== null || $persisted->requiresGatedWriter() || $this->requiresGatedWriter()) {
                    throw new \DomainException('gated_transfer_document_writer_required');
                }
            } else {
                $this->assertGatedWriter($writer);
            }
            return parent::save($options);
        });
    }

    protected static function booted(): void
    {
        $reject = static function (): void {
            throw \Illuminate\Validation\ValidationException::withMessages(['transfer' => 'مستند التحويل المالي غير قابل للتعديل أو الحذف.']);
        };
        static::updating($reject);
        static::deleting($reject);
    }
}

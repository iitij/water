<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PaymentRefund extends Model
{
    protected $fillable = ['payment_id', 'subscriber_id', 'recorded_by', 'amount',
        'disbursement_reference', 'method', 'disbursed_on', 'reason', 'evidence'];
    protected $casts = ['amount' => 'decimal:2', 'disbursed_on' => 'date'];

    public function requiresGatedWriter(): bool
    {
        if (config('financial_candidate.refunds') === true) return true;
        $payment = Payment::withTrashed()->find($this->payment_id);
        return ($payment?->hasRegisteredReceipt() ?? false) || ($this->exists && \App\Services\GatedRefundWriter::hasRegisteredLeg($this));
    }

    public function assertGatedWriter(mixed $writer): void
    {
        if ($writer === null && ! $this->requiresGatedWriter()) return;
        if (! ($writer instanceof \App\Services\GatedRefundWriter && $writer->allowsRecord((int) $this->payment_id, (string) $this->disbursement_reference, (int) $this->recorded_by))) {
            throw new \DomainException('gated_refund_document_writer_required');
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
                    throw new \DomainException('gated_refund_document_writer_required');
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
            throw \Illuminate\Validation\ValidationException::withMessages([
                'refund' => 'مستند الاسترداد المالي غير قابل للتعديل أو الحذف.',
            ]);
        };
        static::updating($reject);
        static::deleting($reject);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'subscriber_id',
        'meter_id',
        'type',
        'amount',
        'description',
        'reference_id',
        'reference_type',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public static function assertInvoicePostingWriter(Invoice $invoice, string $type, mixed $writer): void
    {
        if ($writer === null && config('financial_candidate.invoices') !== true && ! $invoice->hasRegisteredLedger()) return;
        $allowed = $writer instanceof \App\Services\GatedInvoiceTransferWriter
            && match ($type) {
                'invoice' => $writer->allowsIssue((int) $invoice->subscriber_id, (int) $invoice->meter_id),
                'invoice_reversal' => $writer->allowsInvoiceReversal((int) $invoice->id),
                default => false,
            };
        if (! $allowed) throw new \DomainException('gated_invoice_ledger_writer_required');
    }

    public static function assertReceiptPostingService(Payment $payment, mixed $service): void
    {
        if ($service === null && ! Payment::receiptFenceEnabled() && ! $payment->hasRegisteredReceipt()) return;
        if (! ($service instanceof \App\Services\PaymentService && $service->allowsReceiptPosting($payment))) {
            throw new \DomainException('gated_receipt_ledger_writer_required');
        }
    }

    public function save(array $options = [])
    {
        $writer = $options['financial_invoice_writer'] ?? null;
        $receiptService = $options['financial_receipt_service'] ?? null;
        unset($options['financial_invoice_writer'], $options['financial_receipt_service']);
        $receiptRelated = $this->type === 'payment' || ($this->exists && $this->getOriginal('type') === 'payment');
        if ($receiptRelated || $receiptService !== null) {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($options, $writer, $receiptService) {
                if (! \Illuminate\Support\Facades\DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                    throw new \LogicException('Financial issuance lock is missing.');
                }
                $payment = Payment::withTrashed()->find($this->exists ? $this->getOriginal('reference_id') : $this->reference_id);
                if ($writer !== null || ($this->exists && (Payment::receiptFenceEnabled()
                    || $payment?->hasRegisteredReceipt() || $receiptService !== null))) {
                    throw new \DomainException('gated_receipt_ledger_writer_required');
                }
                if (! $this->exists && $payment && $this->reference_type === Payment::class && $this->type === 'payment') {
                    self::assertReceiptPostingService($payment, $receiptService);
                } elseif (Payment::receiptFenceEnabled() || $receiptService !== null) {
                    throw new \DomainException('gated_receipt_ledger_writer_required');
                }
                return parent::save($options);
            });
        }
        $invoiceRelated = $this->reference_type === Invoice::class
            || in_array($this->type, ['invoice', 'invoice_reversal'], true)
            || ($this->exists && ($this->getOriginal('reference_type') === Invoice::class
                || in_array($this->getOriginal('type'), ['invoice', 'invoice_reversal'], true)));
        if (! $invoiceRelated && $writer === null) return parent::save($options);
        return \Illuminate\Support\Facades\DB::transaction(function () use ($options, $writer) {
            if (! \Illuminate\Support\Facades\DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Financial issuance lock is missing.');
            }
            $invoice = Invoice::withTrashed()->find($this->exists ? $this->getOriginal('reference_id') : $this->reference_id);
            if ($this->exists) {
                if (config('financial_candidate.invoices') === true || $invoice?->hasRegisteredLedger() || $writer !== null) {
                    throw new \DomainException('gated_invoice_ledger_writer_required');
                }
            } elseif ($invoice && $this->reference_type === Invoice::class) {
                self::assertInvoicePostingWriter($invoice, $this->type, $writer);
            } elseif (config('financial_candidate.invoices') === true || $writer !== null) {
                throw new \DomainException('gated_invoice_ledger_writer_required');
            }
            return parent::save($options);
        });
    }

    protected static function booted(): void
    {
        $reject = static function (): void {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'transaction' => 'حركة دفتر الأستاذ غير قابلة للتعديل أو الحذف أو الاستعادة. استخدم مستند تصحيح معتمداً.',
            ]);
        };
        static::updating($reject);
        static::deleting($reject);
        static::restoring($reject);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}

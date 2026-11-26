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

    public static function receiptReversalFenceEnabled(): bool
    {
        return Payment::receiptFenceEnabled() || config('financial_candidate.void_api') === true
            || config('financial_candidate.subscriber_transfers') === true;
    }

    public static function assertReceiptReversalWriter(Payment $payment, mixed $writer): void
    {
        if ($writer === null && ! self::receiptReversalFenceEnabled() && ! $payment->hasRegisteredReceipt()) return;
        $allowed = ($writer instanceof \App\Services\GatedReceiptVoidWriter
                && $payment->isVoided() && $writer->allowsMutation($payment))
            || ($writer instanceof \App\Services\GatedInvoiceTransferWriter
                && ! $payment->isVoided() && $writer->allowsReceiptReversal((int) $payment->id));
        if (! $allowed) throw new \DomainException('gated_receipt_reversal_writer_required');
    }

    public static function assertCorrectionPostingWriter(PaymentRefund|PaymentAccountTransfer $document, string $type, mixed $writer): void
    {
        if (($document instanceof PaymentRefund && $type !== 'payment_refund')
            || ($document instanceof PaymentAccountTransfer && $type !== 'payment_transfer_in')) {
            throw new \DomainException('gated_correction_ledger_writer_required');
        }
        try {
            $document->assertGatedWriter($writer);
        } catch (\DomainException $error) {
            throw new \DomainException('gated_correction_ledger_writer_required', previous: $error);
        }
    }

    public static function assertHeldCreditPostingWriter(TreasuryHeldCashCredit $document, string $type, mixed $writer): void
    {
        if ($type !== 'held_cash_credit' || ! ($writer instanceof \App\Services\TreasuryHeldCashLiabilityService
            && $writer->allowsCreditPosting($document))) {
            throw new \DomainException('held_cash_credit_ledger_writer_required');
        }
    }

    public function save(array $options = [])
    {
        $writer = $options['financial_invoice_writer'] ?? null;
        $receiptService = $options['financial_receipt_service'] ?? null;
        $reversalWriter = $options['financial_receipt_reversal_writer'] ?? null;
        $correctionWriter = $options['financial_correction_writer'] ?? null;
        $heldCreditWriter = $options['financial_held_credit_writer'] ?? null;
        unset($options['financial_invoice_writer'], $options['financial_receipt_service'], $options['financial_receipt_reversal_writer'], $options['financial_correction_writer'], $options['financial_held_credit_writer']);
        $heldCreditRelated = $this->type === 'held_cash_credit' || $this->reference_type === TreasuryHeldCashCredit::class
            || ($this->exists && ($this->getOriginal('type') === 'held_cash_credit'
                || $this->getOriginal('reference_type') === TreasuryHeldCashCredit::class));
        if ($heldCreditRelated || $heldCreditWriter !== null) {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($options, $writer, $receiptService, $reversalWriter, $correctionWriter, $heldCreditWriter) {
                if (! \Illuminate\Support\Facades\DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                    throw new \LogicException('Financial issuance lock is missing.');
                }
                if ($this->exists || $writer !== null || $receiptService !== null || $reversalWriter !== null
                    || $correctionWriter !== null || $this->reference_type !== TreasuryHeldCashCredit::class) {
                    throw new \DomainException('held_cash_credit_ledger_writer_required');
                }
                $document = TreasuryHeldCashCredit::query()->lockForUpdate()->find($this->reference_id);
                if ($document === null) throw new \DomainException('held_cash_credit_ledger_writer_required');
                self::assertHeldCreditPostingWriter($document, $this->type, $heldCreditWriter);
                return parent::save($options);
            });
        }
        $correctionTypes = ['payment_refund', 'payment_transfer_in'];
        $correctionClasses = [PaymentRefund::class, PaymentAccountTransfer::class];
        $correctionRelated = in_array($this->type, $correctionTypes, true)
            || in_array($this->reference_type, $correctionClasses, true)
            || ($this->exists && (in_array($this->getOriginal('type'), $correctionTypes, true)
                || in_array($this->getOriginal('reference_type'), $correctionClasses, true)));
        if ($correctionRelated || $correctionWriter !== null) {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($options, $writer, $receiptService, $reversalWriter, $correctionWriter, $correctionClasses) {
                if (! \Illuminate\Support\Facades\DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                    throw new \LogicException('Financial issuance lock is missing.');
                }
                if ($writer !== null || $receiptService !== null || $reversalWriter !== null || $this->exists) {
                    throw new \DomainException('gated_correction_ledger_writer_required');
                }
                $class = $this->reference_type;
                $document = in_array($class, $correctionClasses, true) ? $class::query()->lockForUpdate()->find($this->reference_id) : null;
                if ($document === null) {
                    throw new \DomainException('gated_correction_ledger_writer_required');
                }
                self::assertCorrectionPostingWriter($document, $this->type, $correctionWriter);
                return parent::save($options);
            });
        }
        $reversalRelated = $this->type === 'payment_reversal'
            || ($this->exists && $this->getOriginal('type') === 'payment_reversal');
        if ($reversalRelated || $reversalWriter !== null) {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($options, $writer, $receiptService, $reversalWriter) {
                if (! \Illuminate\Support\Facades\DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                    throw new \LogicException('Financial issuance lock is missing.');
                }
                $payment = Payment::withTrashed()->find($this->exists ? $this->getOriginal('reference_id') : $this->reference_id);
                $protected = self::receiptReversalFenceEnabled() || $payment?->hasRegisteredReceipt() || $reversalWriter !== null;
                if ($writer !== null || $receiptService !== null || ($this->exists && $protected)) {
                    throw new \DomainException('gated_receipt_reversal_writer_required');
                }
                if (! $this->exists && $payment && $this->reference_type === Payment::class && $this->type === 'payment_reversal') {
                    self::assertReceiptReversalWriter($payment, $reversalWriter);
                } elseif ($protected) {
                    throw new \DomainException('gated_receipt_reversal_writer_required');
                }
                return parent::save($options);
            });
        }
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

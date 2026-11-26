<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TreasuryHeldCashCredit extends Model
{
    public $timestamps = false;
    protected $fillable = ['operation_uuid', 'assignment_uuid', 'subscriber_id', 'approved_by',
        'amount', 'effective_on', 'reference', 'evidence'];
    protected $casts = ['amount' => 'decimal:2'];

    public function save(array $options = [])
    {
        $writer = $options['financial_held_credit_writer'] ?? null;
        unset($options['financial_held_credit_writer']);
        if ($this->exists || ! ($writer instanceof \App\Services\TreasuryHeldCashLiabilityService
            && $writer->allowsCreditCreation($this))) {
            throw new \DomainException('held_cash_credit_document_writer_required');
        }
        return parent::save($options);
    }

    protected static function booted(): void
    {
        $reject = static function (): void {
            throw new \DomainException('held_cash_credit_immutable');
        };
        static::updating($reject);
        static::deleting($reject);
    }
}

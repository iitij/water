<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * جلسة صندوق محصّل واحد في يوم واحد.
 *
 * @property string $state one of: open, closed, approved, rejected
 */
class CashSession extends Model
{
    public const STATE_OPEN = 'open';
    public const STATE_CLOSED = 'closed';
    public const STATE_APPROVED = 'approved';
    public const STATE_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'square_id',
        'session_date',
        'opening_amount',
        'settlement_basis',
        'expected_amount',
        'counted_amount',
        'variance_amount',
        'state',
        'opened_at',
        'closed_at',
        'approved_at',
        'approved_by',
        'opening_notes',
        'closing_notes',
        'approval_notes',
    ];

    protected $casts = [
        'session_date' => 'date',
        'opening_amount' => 'decimal:2',
        'expected_amount' => 'decimal:2',
        'counted_amount' => 'decimal:2',
        'variance_amount' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function square(): BelongsTo
    {
        return $this->belongsTo(Square::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('state', self::STATE_OPEN);
    }

    /** الصناديق التي تنتظر قرار المشرف (أُغلقت ولم تُعتمد بعد). */
    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('state', self::STATE_CLOSED);
    }

    public function isOpen(): bool
    {
        return $this->state === self::STATE_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->state === self::STATE_CLOSED;
    }

    public function isApproved(): bool
    {
        return $this->state === self::STATE_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->state === self::STATE_REJECTED;
    }

    /** هل يمكن للمشرف اعتماده أو رفضه الآن؟ */
    public function awaitsDecision(): bool
    {
        return $this->isClosed();
    }
}

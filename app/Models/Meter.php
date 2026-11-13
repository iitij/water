<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use Illuminate\Database\Eloquent\SoftDeletes;

class Meter extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'unique_code',
        'path_number',
        'subscriber_id',
        'status',
        'balance',
        'installed_at',
        'removed_at',
        'initial_reading',
    ];

    protected $casts = [
        'installed_at' => 'date',
        'removed_at' => 'date',
        'initial_reading' => 'integer',
        'balance' => 'decimal:2',
        'path_number' => 'integer',
    ];

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function readings(): HasMany
    {
        return $this->hasMany(Reading::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * آخر قراءة للعداد
     */
    public function isCurrent(): bool
    {
        return $this->removed_at === null && $this->deleted_at === null;
    }

    public function getLatestReading(?int $ignoreReadingId = null): ?Reading
    {
        $query = $this->readings()->orderBy('id', 'desc');
        if ($ignoreReadingId) {
            $query->where('id', '!=', $ignoreReadingId);
        }
        return $query->first();
    }

    /**
     * آخر قيمة قراءة (لاستخدامها كقراءة سابقة)
     */
    public function getLastReadingValue(?int $ignoreReadingId = null): float
    {
        $latest = $this->getLatestReading($ignoreReadingId);
        return $latest ? (float) $latest->current_reading : (float) ($this->initial_reading ?? 0);
    }

    /**
     * حساب الرصيد المدين للعداد (إذا كان الرصيد بالسالب فهو دين)
     */
    public function getOutstandingBalance(): float
    {
        return max(0, -1 * (float) $this->balance);
    }
}

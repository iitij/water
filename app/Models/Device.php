<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * جهاز مرتبط بحساب مستخدم. إبطال الجهاز يمنع أي طلب مُصادَق صادر منه.
 */
class Device extends Model
{
    protected $fillable = [
        'user_id',
        'device_uuid',
        'name',
        'platform',
        'app_version',
        'last_seen_at',
        'revoked_at',
        'revoked_by',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}

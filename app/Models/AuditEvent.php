<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حدث تدقيق واحد. الصفوف تُكتب مرة واحدة ولا تُعدَّل من التطبيق.
 */
class AuditEvent extends Model
{
    protected $fillable = [
        'actor_user_id',
        'event',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'device_id',
        'source',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

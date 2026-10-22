<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceRequest extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'subscriber_id',
        'invoice_id',
        'service_type',
        'amount',
        'service_date',
        'status',
        'notes',
    ];

    protected $casts = [
        'service_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionFee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'subscriber_id',
        'meter_id',
        'amount',
        'status',
        'invoice_id',
        'notes',
    ];

    public function subscriber()
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function meter()
    {
        return $this->belongsTo(Meter::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}

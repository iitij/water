<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileAppLog extends Model
{
    protected $fillable = ['user_id', 'device_id', 'level', 'source', 'message', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}

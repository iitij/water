<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Square extends Model
{
    protected $fillable = [
        'name',
        'description',
    ];

    public function subscribers(): HasMany
    {
        return $this->hasMany(Subscriber::class);
    }
}

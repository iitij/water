<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Square extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
    ];

    protected $attributes = ['code' => null];

    public function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = $value === null || trim($value) === '' ? null : trim($value);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->code === null ? $this->name : $this->code.' — '.$this->name;
    }

    public function subscribers(): HasMany
    {
        return $this->hasMany(Subscriber::class);
    }
}

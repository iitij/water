<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class MobileDeveloperSetting extends Model
{
    // The singleton must retain id=1 even when the database's auto-increment
    // counter has advanced (for example after rolled-back test transactions).
    protected $fillable = ['id', 'pin_hash', 'show_whatsapp_settings', 'allow_log_upload'];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return ['show_whatsapp_settings' => 'boolean', 'allow_log_upload' => 'boolean'];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1], [
            'pin_hash' => Hash::make('77288'),
            'show_whatsapp_settings' => true,
            'allow_log_upload' => false,
        ]);
    }
}

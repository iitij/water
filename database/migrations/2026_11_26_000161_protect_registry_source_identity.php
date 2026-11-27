<?php

use App\Services\RegistrySourceIdentityProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(RegistrySourceIdentityProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Registry source identity protection requires reviewed recovery.');
    }
};

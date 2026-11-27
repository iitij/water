<?php

use App\Services\ObservationGraphFreezeProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(ObservationGraphFreezeProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Observation freeze protection requires independently reviewed recovery.');
    }
};

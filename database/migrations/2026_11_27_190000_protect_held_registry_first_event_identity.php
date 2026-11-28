<?php

use App\Services\HeldRegistryLinkProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        app(HeldRegistryLinkProtection::class)->install();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS fin_held_link_registry_first_event');
    }
};

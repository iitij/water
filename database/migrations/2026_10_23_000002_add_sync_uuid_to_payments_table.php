<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'sync_uuid')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->uuid('sync_uuid')->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'sync_uuid')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropUnique(['sync_uuid']);
                $table->dropColumn('sync_uuid');
            });
        }
    }
};

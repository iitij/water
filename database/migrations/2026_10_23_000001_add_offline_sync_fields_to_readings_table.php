<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('readings', function (Blueprint $table) {
            if (! Schema::hasColumn('readings', 'sync_uuid')) {
                $table->uuid('sync_uuid')->nullable()->unique();
            }
            if (! Schema::hasColumn('readings', 'image_path')) {
                $table->string('image_path')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('readings', function (Blueprint $table) {
            if (Schema::hasColumn('readings', 'image_path')) {
                $table->dropColumn('image_path');
            }
            if (Schema::hasColumn('readings', 'sync_uuid')) {
                $table->dropUnique(['sync_uuid']);
                $table->dropColumn('sync_uuid');
            }
        });
    }
};

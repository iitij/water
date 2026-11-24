<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Install the guard first: a host without trigger privileges must fail
        // closed, not silently expose cascading deletion of historical identities.
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS protect_user_identity_delete BEFORE DELETE ON users BEGIN SELECT RAISE(ABORT, 'User identity cannot be deleted'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $exists = DB::table('information_schema.TRIGGERS')
                ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                ->where('TRIGGER_NAME', 'protect_user_identity_delete')->exists();
            if (! $exists) {
                DB::unprepared("CREATE TRIGGER protect_user_identity_delete BEFORE DELETE ON users FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User identity cannot be deleted'");
            }
        } else {
            throw new RuntimeException('User identity protection requires SQLite or MySQL.');
        }

        if (! Schema::hasColumn('users', 'disabled_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('disabled_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('User identity protection cannot be removed by rollback.');
    }
};

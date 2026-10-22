<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

use Spatie\Permission\Models\Role;

class TestUsersSeeder extends Seeder
{
    public function run()
    {
        // Ensure roles exist
        Role::firstOrCreate(['name' => 'reader', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'collector', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $reader = User::firstOrCreate(
            ['email' => 'reader@water.test'],
            ['name' => 'قارئ عدادات', 'password' => Hash::make('password')]
        );
        $reader->assignRole('reader');

        $collector = User::firstOrCreate(
            ['email' => 'collector@water.test'],
            ['name' => 'محصل ديون', 'password' => Hash::make('password')]
        );
        $collector->assignRole('collector');
    }
}

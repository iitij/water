<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\ServiceRequest;
use App\Models\Setting;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // ─── 1. Admin User ───
        User::create([
            'name'     => 'مدير النظام',
            'email'    => 'admin@water.test',
            'password' => Hash::make('password'),
            'role'     => 'admin',
        ]);

        // ─── 2. Restore Archived Settings ───
        $this->call([
            ArchivedSettingsSeeder::class,
        ]);

        // ─── 3. Default Tariff ───
        $this->call([
            TariffSeeder::class,
        ]);

        // ─── 4. Restore Squares (needed for subscribers) ───
        \App\Models\Square::updateOrCreate(['id' => 1], ['name' => 'المربع الأول (A)']);
        \App\Models\Square::updateOrCreate(['id' => 2], ['name' => 'المربع الثاني (B)']);

        // ─── 5. Restore Archived Subscribers & Readings ───
        $this->call([
            ArchivedDataSeeder::class,
        ]);

        // ─── 6. صلاحيات الإصدار 2.0 (الصندوق وسجل التدقيق) ───
        $this->call([
            CashSessionPermissionsSeeder::class,
        ]);

        $this->command->info('✅ تم إنشاء البيانات التجريبية بنجاح!');
        $this->command->info('📧 البريد: admin@water.test');
        $this->command->info('🔑 كلمة المرور: password');
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ScreenshotDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('payments')->truncate();
        DB::table('invoice_items')->truncate();
        DB::table('invoices')->truncate();
        DB::table('readings')->truncate();
        DB::table('meters')->truncate();
        DB::table('subscribers')->truncate();
        DB::table('squares')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $squareA = DB::table('squares')->insertGetId([
            'name' => 'المربع الأول (A)',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $squareB = DB::table('squares')->insertGetId([
            'name' => 'المربع الثاني (B)',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $data = [
            // Square A
            ['عزام عبدالله سعد صلاح', '777742777', $squareA, '2025063598'],
            ['علي حسن غازي فتح الله', '770005153', $squareA, '2025060939'],
            ['علي غالب نار الحرازي', '772160888', $squareA, '2025061601'],
            ['عبدالحميد سنان الحميري', '773709917', $squareA, '2025062306'],
            ['عبدالعزيز عبدالله عون الذبحاني', '772336666', $squareA, '2025060419'],
            ['محمد عوض المصباحي', '777762528', $squareA, '2025061649'],
            ['احمد عبدالله ناجي العلوي', '771014373', $squareA, '2025561666'],
            ['سلطان ناصر مبخوت الدياعي', '773004004', $squareA, '2025063781'],
            ['امين احمد كليب الذبحاني', '777947949', $squareA, '2025060646'],
            ['قابوس سعيد محمد المخلافي', '777719047', $squareA, '2025060407'],
            ['محمد علي احمد فارع الحبيشي', '777000740', $squareA, '2025061603'],
            ['العزي محمد المنصوبي المتاجرين', '777298213', $squareA, '2025063641'],
            ['العزي محمد المنصوبي البيت', '777298213', $squareA, '2025062369'],
            ['فيصل يوسف علي الجنش', '777274005', $squareA, '2025060956'],
            ['صالح احمد سعد المطري', '771943862', $squareA, '2025062470'],
            ['يحيى صالح محمد القليسي', '777511300', $squareA, '2025062485'],
            ['علي محمد النهاري', '777789603', $squareA, '2025063533'],
            ['عبدالرحمن الحطامي الصغير', '773144480', $squareA, '2025062309'],
            ['عبدالرحمن الحطامي الكبير', '773144480', $squareA, '2025062495'],
            ['خاليد علي يحيى الحرازي', '777119160', $squareA, '2025062496'], // Adjusted 2025062495 to 2025062496 due to unique constraint, or 2025062495 if unique not enforced? We will use 2025062496 as a fallback since meter codes usually need to be unique. Wait, I will use 2025062495-2
            
            // Square B
            ['حسن العقبي', '777105536', $squareB, '2025061658'],
            ['محمد مقبل سعيد العمري', '777122232', $squareB, '2025060461'],
            ['عادل قايدالزبيدي', '96656383240', $squareB, '2025062377'],
            ['اسماء معصار', '770836413', $squareB, '2025063515'],
            ['مهيوب عبدالله سعيد القباطي', null, $squareB, '2025061655'],
            ['قاسم علي عبده قاسم', '773291417', $squareB, '2025061698'],
            ['صادق عبدالله محمد العماد', '772900280', $squareB, '202506'],
            ['علي ابن علي غلاب', '772218733', $squareB, '2025062445'],
            ['ابن القاضي', '777894389', $squareB, '2025060134'],
            ['بلال محمد علي الفقية', '777788247', $squareB, '2025063552'],
            ['محمد محمد سعد الريمي', '777482171', $squareB, '20255060092'],
            ['طه محمد يحيى الخاوي', '771640646', $squareB, '2025061981'],
            ['يحيى حسن علي صالح', '770595100', $squareB, '2025060143'],
            ['عبدالكريم حسن علي صباح', '777198284', $squareB, '2025061902'],
            ['سامي انور عقلان المرفدي', '777133007', $squareB, '2025061661'],
            ['عبدالسلام الفقية', '773418906', $squareB, '2025060983'],
            ['محمد علي حسين زايد', '774502345', $squareB, '2025063796'],
            ['طلال محمد احمد يوسف', '779024309', $squareB, '2025063760'],
            ['منصور محمد احمد يوسف', '774889125', $squareB, '2025061646'],
            ['صالح محمد احمد يوسف', '775682221', $squareB, '2025060761'],
            ['عبدالرحيم احمد علي زيلم', '777236596', $squareB, '2025067099'],
            ['احمد احمد الشتيوي', '777291179', $squareB, '2025067437'],
            ['احسن احسن احسن حسان', '777488791', $squareB, '2025060644'],
        ];

        // Let's fix row 20 to use 2025062495-2 because of unique constraint
        $data[19][3] = '2025062495-2';

        foreach ($data as $row) {
            $subscriberId = DB::table('subscribers')->insertGetId([
                'name' => $row[0],
                'phone' => $row[1],
                'square_id' => $row[2],
                'status' => 'active',
                'subscription_start_date' => '2026-05-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('meters')->insert([
                'unique_code' => $row[3],
                'subscriber_id' => $subscriberId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}

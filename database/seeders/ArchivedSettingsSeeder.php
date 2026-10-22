<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ArchivedSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
  0 => 
  [
    'id' => 1,
    'key' => 'reading_period',
    'type' => 'text',
    'value' => 'monthly',
    'image_path' => NULL,
    'label' => 'دورة القراءات',
    'created_at' => '2026-06-09 03:22:03',
    'updated_at' => '2026-06-09 03:22:03',
  ],
  1 => 
  [
    'id' => 2,
    'key' => 'allowed_days_before_period_end',
    'type' => 'text',
    'value' => '4',
    'image_path' => NULL,
    'label' => 'أيام مسموحة قبل',
    'created_at' => '2026-06-09 03:22:03',
    'updated_at' => '2026-06-09 03:22:03',
  ],
  2 => 
  [
    'id' => 3,
    'key' => 'allowed_days_after_period_end',
    'type' => 'text',
    'value' => '4',
    'image_path' => NULL,
    'label' => 'أيام مسموحة بعد',
    'created_at' => '2026-06-09 03:22:03',
    'updated_at' => '2026-06-09 03:22:03',
  ],
  3 => 
  [
    'id' => 4,
    'key' => 'unit_price',
    'type' => 'text',
    'value' => '600',
    'image_path' => NULL,
    'label' => 'سعر الوحدة (ريال)',
    'created_at' => '2026-06-09 03:22:03',
    'updated_at' => '2026-06-09 03:22:03',
  ],
  4 => 
  [
    'id' => 5,
    'key' => 'currency',
    'type' => 'text',
    'value' => 'YER',
    'image_path' => NULL,
    'label' => 'العملة',
    'created_at' => '2026-06-09 03:22:03',
    'updated_at' => '2026-06-09 03:22:03',
  ],
  5 => 
  [
    'id' => 6,
    'key' => 'subscription_fee',
    'type' => 'text',
    'value' => '0',
    'image_path' => NULL,
    'label' => 'رسوم الاشتراك',
    'created_at' => '2026-06-09 03:22:03',
    'updated_at' => '2026-06-09 03:22:03',
  ],
  6 => 
  [
    'id' => 7,
    'key' => 'invoice_project_name_ar',
    'type' => 'text',
    'value' => 'مشروع مياه الوشاح',
    'image_path' => NULL,
    'label' => 'إعدادات الفاتورة',
    'created_at' => '2026-06-09 03:22:03',
    'updated_at' => '2026-06-09 22:13:42',
  ],
  7 => 
  [
    'id' => 8,
    'key' => 'invoice_address_ar',
    'type' => 'text',
    'value' => 'حارة الفوارس – سعوان',
    'image_path' => NULL,
    'label' => 'إعدادات الفاتورة',
    'created_at' => '2026-06-09 03:22:03',
    'updated_at' => '2026-06-09 22:13:42',
  ],
  8 => 
  [
    'id' => 9,
    'key' => 'invoice_phone_1',
    'type' => 'text',
    'value' => '712271220',
    'image_path' => NULL,
    'label' => 'إعدادات الفاتورة',
    'created_at' => '2026-06-09 03:22:03',
    'updated_at' => '2026-06-09 22:13:42',
  ],
  9 => 
  [
    'id' => 10,
    'key' => 'invoice_phone_2',
    'type' => 'text',
    'value' => '777760193',
    'image_path' => NULL,
    'label' => 'إعدادات الفاتورة',
    'created_at' => '2026-06-09 03:22:03',
    'updated_at' => '2026-06-09 22:13:42',
  ],
  10 => 
  [
    'id' => 11,
    'key' => 'invoice_project_name_en',
    'type' => 'text',
    'value' => 'Washah Water Project',
    'image_path' => NULL,
    'label' => 'إعدادات الفاتورة',
    'created_at' => '2026-06-09 03:22:03',
    'updated_at' => '2026-06-09 22:13:42',
  ],
  11 => 
  [
    'id' => 12,
    'key' => 'invoice_address_en',
    'type' => 'text',
    'value' => 'Harat Al-Fawaris - Sa\'wan',
    'image_path' => NULL,
    'label' => 'إعدادات الفاتورة',
    'created_at' => '2026-06-09 03:22:03',
    'updated_at' => '2026-06-09 22:13:42',
  ],
  12 => 
  [
    'id' => 13,
    'key' => 'invoice_collector_name',
    'type' => 'text',
    'value' => 'علي الوشاح',
    'image_path' => NULL,
    'label' => 'إعدادات الفاتورة',
    'created_at' => '2026-06-09 03:22:03',
    'updated_at' => '2026-06-09 22:13:42',
  ],
  13 => 
  [
    'id' => 14,
    'key' => 'invoice_logo',
    'type' => 'image',
    'value' => 'settings/invoices/01KTQ831TNG3BR0ZM4ANQPEGZ5.png',
    'image_path' => 'settings/01KTEMRP46MC0QFYDF991GCDP5.png',
    'label' => 'إعدادات الفاتورة',
    'created_at' => '2026-06-09 03:22:03',
    'updated_at' => '2026-06-09 22:29:44',
  ],
  14 => 
  [
    'id' => 15,
    'key' => 'invoice_watermark',
    'type' => 'text',
    'value' => 'settings/invoices/01KTQ75NQP7F7SZRRTEQKN1YEP.png',
    'image_path' => NULL,
    'label' => 'إعدادات الفاتورة',
    'created_at' => '2026-06-09 22:13:42',
    'updated_at' => '2026-06-09 22:13:42',
  ],
];

        foreach (array_chunk($data, 100) as $chunk) {
            DB::table('settings')->insert($chunk);
        }
    }
}

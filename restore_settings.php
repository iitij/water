<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$s = App\Models\Setting::class;
$s::updateOrCreate(['key'=>'invoice_project_name_ar'], ['value'=>'مشروع مياه الوشاح', 'label'=>'اسم المشروع بالعربي', 'type'=>'text']);
$s::updateOrCreate(['key'=>'invoice_address_ar'], ['value'=>'حارة الفوارس – سعوان', 'label'=>'العنوان بالعربي', 'type'=>'text']);
$s::updateOrCreate(['key'=>'invoice_phone_1'], ['value'=>'1234567890', 'label'=>'الهاتف 1', 'type'=>'text']);
$s::updateOrCreate(['key'=>'invoice_phone_2'], ['value'=>'1234567890', 'label'=>'الهاتف 2', 'type'=>'text']);
$s::updateOrCreate(['key'=>'invoice_project_name_en'], ['value'=>'Washah Water Project', 'label'=>'اسم المشروع انجليزي', 'type'=>'text']);
$s::updateOrCreate(['key'=>'invoice_address_en'], ['value'=>'Harat Al-Fawaris – Sa\'wan', 'label'=>'العنوان انجليزي', 'type'=>'text']);
$s::updateOrCreate(['key'=>'invoice_collector_name'], ['value'=>'علي الوشاح', 'label'=>'اسم المحصل', 'type'=>'text']);
$s::updateOrCreate(['key'=>'invoice_logo'], ['image_path'=>'settings/01KTEMRP46MC0QFYDF991GCDP5.png', 'label'=>'شعار الفاتورة', 'type'=>'image']);
echo "Settings Restored\n";

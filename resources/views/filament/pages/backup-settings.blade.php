<x-filament-panels::page>
    @php
        $overview = $this->backupOverview();
        $format = fn ($value) => $value ? \Carbon\CarbonImmutable::createFromTimestamp($value, 'Asia/Aden')->format('Y-m-d H:i') : 'لم يسجل بعد';
        $result = $overview['status']['result'] ?? null;
    @endphp
    <x-filament::section>
        <x-slot name="heading">حالة النسخ الاحتياطي</x-slot>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">حالة الجدولة</p>
                <x-filament::badge :color="$overview['policy']['enabled'] ? 'success' : 'gray'">
                    {{ $overview['policy']['enabled'] ? 'مفعلة' : 'متوقفة' }}
                </x-filament::badge>
            </div>
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">الموعد التالي (اليمن)</p>
                <p>{{ $overview['policy']['enabled'] ? $format($overview['next']) : 'غير مجدول' }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">آخر نسخة من المجدول الجديد</p>
                <p>{{ $format($overview['status']['success_at'] ?? null) }}</p>
                <x-filament::badge :color="$result === 'success' ? 'success' : ($result === 'failed' ? 'danger' : 'gray')">
                    {{ match($result) { 'success' => 'ناجحة', 'failed' => 'فشلت المحاولة', 'running' => 'قيد التنفيذ', default => 'بانتظار أول تشغيل' } }}
                </x-filament::badge>
            </div>
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">آخر تحقق أبلغ عنه الجهاز المعتمد</p>
                <p>{{ $format($overview['device']['verified_at'] ?? null) }}</p>
                <x-filament::badge color="gray">{{ $overview['policy']['destination'] === 'msi' ? 'MSI — Ubuntu' : 'الاستضافة فقط' }}</x-filament::badge>
            </div>
        </div>
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">النسخ مشفرة؛ لا تظهر مفاتيح الاستعادة في الموقع. الحذف التلقائي للنسخ غير مفعل.</p>
    </x-filament::section>
    <form wire:submit="submit">
        {{ $this->form }}
        <div class="mt-4">
            <x-filament::button type="submit">حفظ الإعدادات</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">تصدير ملفات Excel</x-slot>
        <x-slot name="description">تنزيل بيانات النظام بصيغة XLSX القياسية مع اختيار عوامل التصفية المناسبة.</x-slot>
        <div class="flex flex-wrap gap-3">
            <x-filament::badge color="gray">إجمالي المشتركين: {{ number_format($totalSubscribers) }}</x-filament::badge>
            <x-filament::badge color="gray">إجمالي الفواتير: {{ number_format($totalInvoices) }}</x-filament::badge>
            <x-filament::badge color="gray">إجمالي القراءات: {{ number_format($totalReadings) }}</x-filament::badge>
        </div>
    </x-filament::section>
    {{ $this->form }}
</x-filament-panels::page>

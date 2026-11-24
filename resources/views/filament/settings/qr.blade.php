@php
    $localServerStatus = $getLivewire()->localServerStatus ?? 'offline';
    $localServerQr = $getLivewire()->localServerQr ?? null;
@endphp

<div wire:poll.3s="checkLocalServerStatus" class="grid gap-4">
    <x-filament::section>
        <x-slot name="heading">حالة خادم واتساب</x-slot>
        <div class="grid gap-3">
            @if ($localServerStatus === 'connected')
                <x-filament::badge color="success" icon="heroicon-m-check-circle">متصل</x-filament::badge>
                <p class="text-sm">الخادم يعمل وجاهز لاستقبال طلبات إرسال الفواتير بصيغة PDF آلياً.</p>
                <x-filament::button wire:click="stopLocalServer" icon="heroicon-m-stop" color="danger">إيقاف الخادم</x-filament::button>
            @elseif ($localServerStatus === 'qr')
                <x-filament::badge color="warning" icon="heroicon-m-qr-code">بانتظار الربط</x-filament::badge>
                <p class="text-sm">امسح رمز QR باستخدام تطبيق واتساب لربط رقمك.</p>
                @if ($localServerQr)
                    {{-- White background is required for QR scan contrast in both themes. --}}
                    <div class="flex justify-center">
                        <img src="{{ $localServerQr }}" alt="WhatsApp QR Code" width="256" height="256" style="background: white; padding: 8px; max-width: 100%; height: auto;" />
                    </div>
                @endif
                <p class="text-sm text-gray-600 dark:text-gray-400">يتحدث الرمز تلقائياً.</p>
            @elseif ($localServerStatus === 'waiting')
                <x-filament::badge color="warning">جاري تجهيز الخادم</x-filament::badge>
                <div class="flex items-center gap-2">
                    <x-filament::loading-indicator class="h-5 w-5" />
                    <span>بانتظار رمز QR من واتساب...</span>
                </div>
            @else
                <x-filament::badge color="danger" icon="heroicon-m-x-circle">غير متصل</x-filament::badge>
                <p class="text-sm">شغّل الخادم من النظام أو باستخدام ملف <code>start-whatsapp.bat</code> من مجلد المشروع.</p>
                <x-filament::button wire:click="startLocalServer" icon="heroicon-m-play">تشغيل الخادم</x-filament::button>
            @endif
        </div>
    </x-filament::section>

    <x-filament::section collapsible>
        <x-slot name="heading">سجل خادم واتساب</x-slot>
        <pre dir="ltr" class="max-h-48 overflow-auto whitespace-pre-wrap break-all text-xs text-left">{{ $getLivewire()->whatsappLogs }}</pre>
    </x-filament::section>
    <x-filament::section collapsible>
        <x-slot name="heading">سجل طابور الإرسال</x-slot>
        <pre dir="ltr" class="max-h-48 overflow-auto whitespace-pre-wrap break-all text-xs text-left">{{ $getLivewire()->queueLogs }}</pre>
    </x-filament::section>
</div>

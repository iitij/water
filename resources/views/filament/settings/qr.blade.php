@php
    $localServerStatus = $getLivewire()->localServerStatus ?? 'offline';
    $localServerQr = $getLivewire()->localServerQr ?? null;
@endphp

<div wire:poll.3s="checkLocalServerStatus" style="display: flex; flex-direction: column; gap: 1rem; width: 100%;">
    @if($localServerStatus === 'connected')
        <div style="display: flex; align-items: center; gap: 0.5rem; color: #10b981; font-weight: bold;">
            <x-filament::icon icon="heroicon-m-check-circle" style="width: 24px; height: 24px;" />
            <span>الخادم يعمل وجاهز لاستقبال طلبات إرسال الفواتير بصيغة PDF آلياً.</span>
        </div>
        <p style="font-size: 0.875rem; color: #6b7280;">الخادم الآن يعمل بصمت في الخلفية ولا توجد أي نافذة سوداء.</p>
        
        <div style="margin-top: 0.5rem;">
            <x-filament::button wire:click="stopLocalServer" icon="heroicon-m-stop" color="danger">
                إيقاف الخادم نهائياً
            </x-filament::button>
        </div>
        
    @elseif($localServerStatus === 'qr')
        <div style="display: flex; flex-direction: column; align-items: center; gap: 1rem; text-align: center;">
            <div style="display: flex; align-items: center; gap: 0.5rem; color: #eab308; font-weight: bold;">
                <x-filament::icon icon="heroicon-m-qr-code" style="width: 24px; height: 24px;" />
                <span>الرجاء مسح هذا الكود باستخدام تطبيق واتساب لربط رقمك.</span>
            </div>
            @if($localServerQr)
                <div style="padding: 0.5rem; background: white; border-radius: 0.5rem; border: 2px solid #e5e7eb; display: inline-block;">
                    <img src="{{ $localServerQr }}" alt="WhatsApp QR Code" style="width: 256px; height: 256px; display: block;" />
                </div>
            @endif
            <p style="font-size: 0.75rem; color: #6b7280;">يتحدث الكود كل بضع ثوان تلقائياً.</p>
        </div>
        
    @elseif($localServerStatus === 'waiting')
        <div style="display: flex; flex-direction: column; align-items: center; gap: 1rem; text-align: center; color: #6b7280;">
            <svg style="width: 32px; height: 32px; animation: spin 1s linear infinite;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle style="opacity: 0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path style="opacity: 0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <p>جاري تجهيز الخادم وطلب كود QR من واتساب...</p>
            <style>
                @keyframes spin { 100% { transform: rotate(360deg); } }
            </style>
        </div>
        
    @else
        <div style="display: flex; align-items: flex-start; gap: 1rem; padding: 1rem; background-color: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 0.5rem; color: #ef4444;">
            <x-filament::icon icon="heroicon-m-x-circle" style="width: 24px; height: 24px; flex-shrink: 0;" />
            <div>
                <p style="font-weight: bold; margin-bottom: 0.5rem;">الخادم لا يعمل حالياً (مطفأ)</p>
                <p style="font-size: 0.875rem; line-height: 1.5;">انقر على الزر أدناه لتشغيل الخادم مباشرة من النظام، أو قم بتشغيل ملف <code>start-whatsapp.bat</code> يدوياً من مجلد المشروع.</p>
            </div>
        </div>
        
        <div style="margin-top: 0.5rem;">
            <x-filament::button wire:click="startLocalServer" icon="heroicon-m-play" color="primary">
                تشغيل الخادم من هنا
            </x-filament::button>
        </div>
    @endif

    <div style="margin-top: 2rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
        <div>
            <h4 style="font-weight: bold; margin-bottom: 0.5rem; color: #374151;">شاشة خادم الواتساب (WhatsApp Server)</h4>
            <pre style="background-color: #111827; color: #34d399; padding: 1rem; border-radius: 0.5rem; font-family: monospace; font-size: 0.75rem; overflow-y: auto; height: 12rem; direction: ltr; text-align: left; white-space: pre-wrap;">{{ $getLivewire()->whatsappLogs }}</pre>
        </div>
        <div>
            <h4 style="font-weight: bold; margin-bottom: 0.5rem; color: #374151;">شاشة طابور الإرسال (Queue Worker)</h4>
            <pre style="background-color: #111827; color: #60a5fa; padding: 1rem; border-radius: 0.5rem; font-family: monospace; font-size: 0.75rem; overflow-y: auto; height: 12rem; direction: ltr; text-align: left; white-space: pre-wrap;">{{ $getLivewire()->queueLogs }}</pre>
        </div>
    </div>
</div>

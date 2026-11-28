<x-filament-panels::page>
    <x-filament::section heading="إدارة أدلة البنك">
        <p>سجّل الكشف كما ورد من البنك، ثم طابق الحركة مع القيد القائم أو اعتمد رسماً أو تحصيلاً مثبتاً بواسطة مراجع مستقل. المطابقة لا تعني اعتماد الإغلاق المحاسبي. هذه الواجهة متاحة للمرشح الاختباري فقط.</p>
    </x-filament::section>
    @php($report = $this->selectedReport())
    @if ($report !== null)
        <x-filament::section :heading="'كشف '.$report['statement']['reference']">
            <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach (['recorded_opening_minor' => 'رصيد البداية المسجل', 'recorded_closing_minor' => 'رصيد النهاية المسجل',
                    'opening_difference_minor' => 'فرق البداية', 'closing_difference_minor' => 'فرق النهاية',
                    'bank_unmatched_minor' => 'صافي حركات البنك غير المطابقة', 'ledger_unmatched_minor' => 'صافي القيود غير المطابقة'] as $key => $label)
                    <div><dt>{{ $label }}</dt><dd>{{ \App\Support\SignedMinor::decimal(\App\Support\SignedMinor::parse($report[$key])) }}</dd></div>
                @endforeach
                <div><dt>المطابقات</dt><dd>{{ $report['matched_count'] }}</dd></div>
                <div><dt>حالة المطابقة</dt><dd>{{ $report['fully_matched'] ? 'مطابق بالكامل' : 'توجد فروق أو حركات غير مطابقة' }}</dd></div>
            </dl>
            <p>لا يمثل هذا التقرير شهادة إغلاق محاسبي.</p>
        </x-filament::section>
        <x-filament::section heading="حركات البنك غير المطابقة">
            @forelse ($report['unmatched_bank_lines'] as $line)
                <div>{{ $line->bank_reference }} | {{ $line->booked_on }} | {{ \App\Support\SignedMinor::decimal(\App\Support\SignedMinor::parse($line->amount_minor)) }}</div>
            @empty
                <p>لا توجد حركات غير مطابقة.</p>
            @endforelse
        </x-filament::section>
        <x-filament::section heading="قيود الخزينة غير المطابقة">
            @forelse ($report['unmatched_ledger_postings'] as $posting)
                <div>{{ $posting->evidence_reference }} | {{ $posting->effective_on }} | {{ \App\Support\SignedMinor::decimal(\App\Support\SignedMinor::parse($posting->amount_minor)) }}</div>
            @empty
                <p>لا توجد قيود غير مطابقة.</p>
            @endforelse
        </x-filament::section>
        @if ($report['issues'] !== [])
            <x-filament::section heading="مشكلات سلامة الأدلة">
                @foreach ($report['issues'] as $issue)
                    <div>{{ $issue['code'] }}</div>
                @endforeach
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>

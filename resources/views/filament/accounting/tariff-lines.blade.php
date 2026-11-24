<div class="space-y-4" dir="rtl">
    @if ($lines->isEmpty())
        <p>لا تتوفر تفاصيل تسعير تاريخية محفوظة لهذه الفاتورة. لا يعني ذلك أن سعر الوحدة صفر.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="p-2 text-start">القراءة</th>
                        <th class="p-2 text-start">الفترة</th>
                        <th class="p-2 text-start">التعرفة</th>
                        <th class="p-2 text-start">الوحدات</th>
                        <th class="p-2 text-start">سعر الوحدة (ريال)</th>
                        <th class="p-2 text-start">المبلغ (ريال)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr class="border-t">
                            <td class="p-2">#{{ $line->reading_id }}</td>
                            <td class="p-2">{{ $line->period_start }} — {{ $line->period_end }}</td>
                            <td class="p-2">{{ $line->tariff_name }}</td>
                            <td class="p-2">{{ number_format($line->units, 2) }}</td>
                            <td class="p-2">{{ number_format($line->unit_price, 2) }}</td>
                            <td class="p-2">{{ number_format($line->amount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p>إجمالي الاستهلاك المفوتر: {{ number_format($lines->sum('amount'), 2) }} ريال</p>
        <p class="text-sm">تستند هذه التفاصيل إلى أدلة التسعير المحفوظة وليست إلى أسعار اليوم.</p>
    @endif
</div>

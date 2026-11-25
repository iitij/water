<div class="space-y-4" dir="rtl">
    @if (($snapshot['integrity_valid'] ?? null) === false)
        <p>تعذر التحقق من سلامة سجل التخصيص. يلزم مراجعة المسؤول المالي.</p>
    @elseif (!$snapshot['available'])
        <p>لا يوجد إصدار تخصيص محفوظ لهذا الحساب بعد. لا يعني ذلك أن السند غير مخصص.</p>
    @else
        <p class="text-sm">الحساب #{{ $snapshot['subscriber_id'] }} — الإصدار {{ $snapshot['revision'] }} — {{ $snapshot['recorded_at'] }}</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr><th class="p-2 text-start">المصدر</th><th class="p-2 text-start">الفاتورة</th><th class="p-2 text-start">المبلغ بالريال</th></tr></thead>
                <tbody>
                @forelse ($snapshot['rows'] as $row)
                    <tr class="border-t">
                        <td class="p-2">@if ($row['held_cash_credit_id'] ?? null) تسوية فائض محتجز #{{ $row['held_cash_credit_id'] }} @else {{ $row['account_transfer_id'] ? 'تحويل محاسبي' : 'سند قبض' }} {{ $row['receipt_number'] }} @endif</td>
                        <td class="p-2">{{ $row['bill_number'] ?? 'رصيد دائن غير مخصص' }}</td>
                        <td class="p-2">{{ number_format($row['amount_minor'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="p-2">لا توجد تخصيصات سارية في هذا الإصدار.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <p>إجمالي المخصص: {{ number_format($snapshot['allocated_minor'] / 100, 2) }} ريال</p>
        <p>غير المخصص: {{ number_format($snapshot['unallocated_minor'] / 100, 2) }} ريال</p>
    @endif
</div>

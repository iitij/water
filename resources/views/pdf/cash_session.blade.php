<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>تقرير صندوق {{ $session->session_date?->format('Y-m-d') }}</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap');

        @page {
            size: A4 portrait;
            margin: 14mm 12mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Tajawal', 'Segoe UI', sans-serif;
            font-size: 11px;
            color: #0b2b40;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        h1 {
            font-size: 18px;
            font-weight: 800;
        }

        .muted {
            color: #5b7183;
            font-size: 10px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #0b6a8f;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 700;
            background: #e1eff5;
            color: #0b6a8f;
        }

        .badge.rejected {
            background: #fdecea;
            color: #a3261b;
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 18px;
            margin-bottom: 12px;
        }

        .meta div {
            min-width: 30%;
        }

        .meta span {
            font-weight: 700;
        }

        .totals {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }

        .total-box {
            flex: 1;
            border: 1px solid #cfdde6;
            border-radius: 6px;
            padding: 8px 10px;
            text-align: center;
        }

        .total-box .label {
            font-size: 9px;
            color: #5b7183;
            margin-bottom: 3px;
        }

        .total-box .value {
            font-size: 14px;
            font-weight: 800;
        }

        .total-box.negative .value {
            color: #a3261b;
        }

        .total-box.positive .value {
            color: #1a7f4b;
        }

        .words {
            background: #f4f9fb;
            border: 1px dashed #9dbecd;
            border-radius: 6px;
            padding: 7px 10px;
            margin-bottom: 12px;
            font-size: 11px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        th,
        td {
            border: 1px solid #cfdde6;
            padding: 5px 6px;
            text-align: right;
        }

        th {
            background: #e1eff5;
            font-size: 10px;
        }

        tr.void td {
            color: #a3261b;
            text-decoration: line-through;
        }

        .section-title {
            font-size: 12px;
            font-weight: 800;
            margin: 10px 0 5px;
        }

        .notes {
            border: 1px solid #cfdde6;
            border-radius: 6px;
            padding: 8px 10px;
            margin-bottom: 12px;
            white-space: pre-wrap;
        }

        .signatures {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-top: 24px;
        }

        .signature {
            flex: 1;
            border-top: 1px solid #7f95a4;
            padding-top: 5px;
            text-align: center;
            font-size: 10px;
            color: #5b7183;
        }

        .footer {
            margin-top: 14px;
            font-size: 9px;
            color: #8399a8;
            text-align: center;
        }
    </style>
</head>

<body>
    @php
        $stateLabels = [
            'open' => 'مفتوح',
            'closed' => 'مغلق بانتظار الاعتماد',
            'approved' => 'معتمد',
            'rejected' => 'مرفوض',
        ];
        $state = $summary['state'] ?? 'open';
    @endphp

    <div class="header">
        <div>
            <h1>تقرير صندوق المحصّل</h1>
            <div class="muted">
                يوم {{ $session->session_date?->format('Y-m-d') }}
                @if ($summary['square'] ?? null)
                    — {{ $summary['square'] }}
                @endif
            </div>
        </div>
        <div>
            <span class="badge {{ $state === 'rejected' ? 'rejected' : '' }}">
                {{ $stateLabels[$state] ?? $state }}
            </span>
            <div class="muted" style="margin-top:4px">رقم الصندوق: {{ $session->id }}</div>
        </div>
    </div>

    <div class="meta">
        <div><span>المحصّل:</span> {{ $summary['collector'] ?? '—' }}</div>
        <div><span>فُتح في:</span> {{ $session->opened_at?->format('Y-m-d H:i') ?? '—' }}</div>
        <div><span>أُغلق في:</span> {{ $session->closed_at?->format('Y-m-d H:i') ?? '—' }}</div>
        <div><span>عدد السندات:</span> {{ $summary['receipts'] ?? 0 }}</div>
        <div><span>سندات ملغاة:</span> {{ $summary['voided_receipts'] ?? 0 }}</div>
        <div><span>اعتُمد بواسطة:</span> {{ $summary['approved_by'] ?? '—' }}</div>
    </div>

    <div class="totals">
        <div class="total-box">
            <div class="label">رصيد الافتتاح</div>
            <div class="value">{{ $summary['opening_amount'] ?? '0.00' }}</div>
        </div>
        <div class="total-box">
            <div class="label">المتوقع من السندات</div>
            <div class="value">{{ $summary['expected_amount'] ?? '0.00' }}</div>
        </div>
        <div class="total-box">
            <div class="label">النقد المعدود</div>
            <div class="value">{{ $summary['counted_amount'] ?? '—' }}</div>
        </div>
        <div class="total-box {{ (float) ($summary['variance_amount'] ?? 0) < 0 ? 'negative' : 'positive' }}">
            <div class="label">الفرق</div>
            <div class="value">{{ $summary['variance_amount'] ?? '—' }}</div>
        </div>
    </div>

    @if ($amountInWords)
        <div class="words">
            <strong>النقد المعدود كتابةً:</strong> {{ $amountInWords }}
        </div>
    @endif

    @if (! empty($summary['by_method']))
        <div class="section-title">توزيع التحصيل حسب طريقة الدفع</div>
        <table>
            <thead>
                <tr>
                    <th>طريقة الدفع</th>
                    <th>عدد السندات</th>
                    <th>الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summary['by_method'] as $method)
                    <tr>
                        <td>{{ $methodLabels[$method['payment_method']] ?? $method['payment_method'] }}</td>
                        <td>{{ $method['receipts'] }}</td>
                        <td>{{ $method['total'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="section-title">سندات الصندوق ({{ count($payments) }})</div>
    <table>
        <thead>
            <tr>
                <th>الوقت</th>
                <th>رقم السند</th>
                <th>المشترك</th>
                <th>العداد</th>
                <th>الطريقة</th>
                <th>المبلغ</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payments as $payment)
                <tr class="{{ $payment['voided'] ? 'void' : '' }}">
                    <td>{{ $payment['time'] ?? '—' }}</td>
                    <td>{{ $payment['receipt_number'] }}</td>
                    <td>{{ $payment['subscriber'] ?? '—' }}</td>
                    <td>{{ $payment['meter'] ?? '—' }}</td>
                    <td>{{ $methodLabels[$payment['payment_method']] ?? $payment['payment_method'] }}</td>
                    <td>{{ $payment['amount'] }}{{ $payment['voided'] ? ' (ملغى)' : '' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align:center">لا سندات في هذا الصندوق.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($session->opening_notes || $session->closing_notes || $session->approval_notes)
        <div class="section-title">الملاحظات</div>
        <div class="notes">
            @if ($session->opening_notes)
                <div><strong>عند الفتح:</strong> {{ $session->opening_notes }}</div>
            @endif
            @if ($session->closing_notes)
                <div><strong>عند الإغلاق:</strong> {{ $session->closing_notes }}</div>
            @endif
            @if ($session->approval_notes)
                <div><strong>عند الاعتماد:</strong> {{ $session->approval_notes }}</div>
            @endif
        </div>
    @endif

    <div class="signatures">
        <div class="signature">توقيع المحصّل: {{ $summary['collector'] ?? '____________' }}</div>
        <div class="signature">توقيع المشرف: {{ $summary['approved_by'] ?? '____________' }}</div>
        <div class="signature">توقيع المراجع المالي: ____________</div>
    </div>

    <div class="footer">
        أُنشئ بواسطة نظام إدارة المياه — {{ now()->format('Y-m-d H:i') }}
        @if ($generatedAt)
            | تاريخ بيانات التقرير: {{ $generatedAt }}
        @endif
    </div>
</body>

</html>

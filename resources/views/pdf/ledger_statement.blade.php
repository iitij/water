<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>كشف حساب المشترك</title>
    <style>
        @page { size: A4 portrait; margin: 14mm 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #123451; }
        h1 { font-size: 19px; border-bottom: 2px solid #123451; padding-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        th, td { border: 1px solid #cfdde6; padding: 5px; text-align: right; }
        th { background: #e1eff5; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        .number { direction: ltr; text-align: left; white-space: nowrap; }
    </style>
</head>
<body>
    <h1>كشف حساب المشترك</h1>
    <p>المشترك: {{ $statement['subscriber']['name'] }} ({{ $statement['subscriber']['id'] }})</p>
    <p>الفترة: <bdi>{{ $statement['from_date'] }}</bdi> — <bdi>{{ $statement['to_date'] }}</bdi></p>
    <p>الأساس: تاريخ الترحيل المحاسبي. الموجب رصيد دائن والسالب مديونية.</p>
    @php $money = fn ($minor) => number_format($minor / 100, 2, '.', ','); @endphp
    <table>
        <thead><tr><th>رصيد الافتتاح</th><th>إجمالي المدين</th><th>إجمالي الدائن</th><th>رصيد الإغلاق</th></tr></thead>
        <tbody><tr>
            @foreach (['opening_balance_minor', 'debit_minor', 'credit_minor', 'closing_balance_minor'] as $key)
                <td class="number">{{ $money($statement[$key]) }}</td>
            @endforeach
        </tr></tbody>
    </table>
    <table>
        <thead><tr><th>القيد</th><th>تاريخ الترحيل</th><th>البيان</th><th>العداد</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr></thead>
        <tbody>
        @forelse ($statement['entries'] as $entry)
            <tr>
                <td>{{ $entry['id'] }}</td><td><bdi>{{ $entry['posting_date'] }}</bdi></td>
                <td>{{ $entry['description'] ?? $entry['type'] }}</td><td>{{ $entry['meter_code'] ?? '—' }}</td>
                <td class="number">{{ $money($entry['debit_minor']) }}</td>
                <td class="number">{{ $money($entry['credit_minor']) }}</td>
                <td class="number">{{ $money($entry['running_balance_minor']) }}</td>
            </tr>
        @empty
            <tr><td colspan="7">لا توجد حركات مرحّلة خلال الفترة.</td></tr>
        @endforelse
        </tbody>
    </table>
    <p>جميع المبالغ بالريال. الكشف يعكس القيود المسجلة؛ ولا يعد اعتماداً لصحة المستندات التاريخية.</p>
</body>
</html>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>سند قبض {{ $payment->receipt_number }}</title>
    <style>
        @include('pdf.partials.fonts')

        @page {
            size: A5 landscape;
            margin: 0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }

        body {
            font-family: 'Tajawal', 'Segoe UI', sans-serif;
            font-size: 11px;
            color: #0b2b40;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .page-wrapper {
            width: 100%;
            height: 100%;
            padding: 20px 25px;
            background: linear-gradient(to bottom, #e1eff5 0%, #ffffff 35%, #ffffff 65%, #e1eff5 100%);
            position: relative;
            box-sizing: border-box;
        }

        /* ===== HEADER ===== */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .header-right {
            text-align: right;
            flex: 1;
        }

        .header-center {
            text-align: center;
            flex: 0 0 160px;
        }

        .header-left {
            text-align: left;
            direction: ltr;
            flex: 1;
        }

        .project-name-ar {
            font-size: 26px;
            font-weight: 800;
            color: #124c6b;
            margin-bottom: 3px;
        }

        .project-name-en {
            font-size: 20px;
            font-weight: 800;
            color: #124c6b;
            margin-bottom: 3px;
        }

        .header-detail {
            font-size: 14px;
            color: #1b3d4f;
            margin: 1px 0;
        }

        .logo-img {
            max-height: 95px;
            max-width: 140px;
        }

        /* ===== TITLE ===== */
        .title-container {
            position: relative;
            text-align: center;
            height: 32px;
            margin: 5px 0 12px 0;
        }

        .title-line {
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            border-bottom: 1.99px solid #02687E;
            z-index: 1;
        }

        .title-text {
            position: relative;
            z-index: 2;
            display: inline-block;
            background: #026b7eff;
            color: #fff;
            font-size: 16px;
            font-weight: 700;
            padding: 4px 40px;
            border-radius: 8px;
            letter-spacing: 0.5px;
        }

        /* ===== ROUNDED TABLES ===== */
        .rounded-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            border: 1px solid rgba(40, 55, 61, 0.5);
            border-radius: 8px;
            overflow: hidden;
            margin-top: 8px;
            background: rgba(251, 253, 254, 0.5);
        }

        .rounded-table td {
            border: 0.5px solid rgba(40, 55, 61, 0.5);
            border-left: none;
            border-bottom: none;
            text-align: center;
            padding: 8px 6px;
            font-size: 12px;
            color: #0b2b40;
            vertical-align: middle;
            font-weight: 500;
        }

        .rounded-table tr:first-child td {
            border-top: none;
        }

        .rounded-table tr:last-child td {
            border-bottom: none;
        }

        .rounded-table tr td:first-child {
            border-right: none;
        }

        .rounded-table tr td:last-child {
            border-left: none;
        }

        .label-cell {
            background: linear-gradient(to bottom, rgba(152, 214, 240, 0.5), rgba(94, 176, 217, 1));
            font-weight: 800 !important;
            font-size: 13px;
            color: #0b2b40;
            width: 15%;
        }

        .value-cell {
            background: rgba(255, 255, 255, 0.6);
            font-weight: 700 !important;
            font-size: 13px;
            width: 35%;
        }

        .section-title {
            background: linear-gradient(to bottom, rgba(152, 214, 240, 0.5), rgba(94, 176, 217, 1));
            font-weight: 800 !important;
            text-align: right !important;
            font-size: 14px;
            padding: 6px 15px !important;
            color: #0b2b40;
        }

        /* ===== BOTTOM SECTION ===== */
        .bottom-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
        }

        .amount-boxes {
            display: flex;
            gap: 10px;
        }

        .due-box {
            display: flex;
            border: 1px solid #19528e;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 2px 2px 5px rgba(0, 0, 0, 0.15);
        }

        .due-label {
            background: linear-gradient(to bottom, rgba(25, 82, 142, 0.85), rgba(15, 48, 87, 0.85));
            color: #fff;
            padding: 8px 15px;
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
        }

        .due-value {
            background: rgba(228, 236, 239, 0.6);
            color: #000;
            padding: 8px 15px;
            font-weight: 800;
            font-size: 16px;
            display: flex;
            align-items: center;
            min-width: 100px;
            justify-content: center;
        }

        /* ===== FOOTER ===== */
        .footer-box {
            border: 1px solid rgba(40, 55, 61, 0.5);
            margin-top: 15px;
            border-radius: 8px;
            padding: 14px 20px;
            background: rgba(251, 253, 254, 0.5);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .footer-box .collector {
            font-weight: 800;
            font-size: 14px;
            color: #0b2b40;
            text-align: center;
        }

        .footer-box .notes {
            font-weight: 600;
            font-size: 13px;
            color: #333;
            max-width: 50%;
        }

        /* ===== WATERMARK ===== */
        .watermark {
            position: absolute;
            top: 55%;
            left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.2;
            z-index: 0;
            pointer-events: none;
        }

        .watermark img {
            width: 300px;
            height: auto;
        }

        .content {
            position: relative;
            z-index: 1;
        }
    </style>
</head>

<body>
    @php
    // Logo
    $logoPath = \App\Models\Setting::get('invoice_logo', null);
    $logoBase64 = null;
    if ($logoPath) {
    $fullPath = storage_path('app/private/' . $logoPath);
    if (!file_exists($fullPath)) {
    $fullPath = storage_path('app/public/' . $logoPath);
    }
    if (file_exists($fullPath)) {
    $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
    $mime = $ext === 'png' ? 'image/png' : ($ext === 'svg' ? 'image/svg+xml' : 'image/' . $ext);
    $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath));
    }
    }

    // Watermark
    $watermarkPath = \App\Models\Setting::get('invoice_watermark', null);
    $watermarkBase64 = null;
    if ($watermarkPath) {
    $fullPath = storage_path('app/private/' . $watermarkPath);
    if (!file_exists($fullPath)) {
    $fullPath = storage_path('app/public/' . $watermarkPath);
    }
    if (file_exists($fullPath)) {
    $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
    $mime = $ext === 'png' ? 'image/png' : ($ext === 'svg' ? 'image/svg+xml' : 'image/' . $ext);
    $watermarkBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath));
    }
    }

    $outstanding = $payment->subscriber->getOutstandingBalance();
    $creditBalance = (float) $payment->subscriber->balance; // رصيد دائن للمشترك

    $tenantName = null;
    $tenantPhone = null;
    if ($payment->invoice && $payment->invoice->meter) {
    $tenantName = $payment->invoice->meter->tenant_name;
    $tenantPhone = $payment->invoice->meter->tenant_phone;
    }

    $invoiceRemaining = 0;
    $dueBefore = 0;
    if ($payment->invoice) {
    $invoiceTotal = $payment->invoice->total_amount + $payment->invoice->previous_balance;

    // Calculate payments made before this one
    // We use credit_applied directly as the credit balance applied to this invoice
    $creditApplied = $payment->invoice->credit_applied ?? 0;

    $directPaidBefore = \App\Models\Payment::where('invoice_id', $payment->invoice_id)
    ->where('id', '<', $payment->id)
        ->sum('amount');

        $paidBefore = $directPaidBefore + $creditApplied;

        $dueBefore = $invoiceTotal - $paidBefore;
        $invoiceRemaining = $dueBefore - $payment->amount;
        }

        // DYNAMIC CONFIGURATION
        $chk = function($key) { return \App\Models\Setting::get($key, '1') == '1'; };
        $lbl = function($key, $default) { return \App\Models\Setting::get($key, $default); };

        $formatPhone = function($phone) {
        if (!$phone || $phone === '-') return '-';
        $phone = str_replace(' ', '', $phone);
        if (str_starts_with($phone, '+967') && strlen($phone) > 4) {
        return '+967 ' . substr($phone, 4);
        }
        if (str_starts_with($phone, '967') && strlen($phone) > 3) {
        return '967 ' . substr($phone, 3);
        }
        if (str_starts_with($phone, '+') && strlen($phone) > 4) {
        return substr($phone, 0, 4) . ' ' . substr($phone, 4);
        }
        return $phone;
        };

        // 1. Receipt Info Array
        $receiptInfoFields = [];
        if($chk('rect_show_receipt_number')) $receiptInfoFields[] = ['l' => $lbl('rect_lbl_receipt_number', 'رقم السند'), 'v' => $payment->receipt_number, 'is_ltr' => true];
        if($chk('rect_show_receipt_date')) $receiptInfoFields[] = ['l' => $lbl('rect_lbl_receipt_date', 'تاريخ السند'), 'v' => $payment->payment_date->format('Y-m-d')];
        $receiptInfoChunks = array_chunk($receiptInfoFields, 2);

        // 2. Subscriber Info Array
        $subFields = [];
        $subNameValue = $payment->subscriber->name;
        if($tenantName) {
        $subNameValue .= '<br><span style="color: #02687E; font-weight: 800; font-size: 11.5px;">' . $lbl('rect_lbl_tenant', 'المستأجر') . ': ' . $tenantName . '</span>';
        }
        if($chk('rect_show_sub_name')) $subFields[] = ['l' => $lbl('rect_lbl_sub_name', 'اسم المشترك'), 'v' => $subNameValue];

        $subPhoneValue = $formatPhone($payment->subscriber->phone ?? '-');
        if($tenantPhone) {
        $subPhoneValue .= '<br><span style="color: #02687E; font-size: 11.5px;">' . $formatPhone($tenantPhone) . '</span>';
        }
        if($chk('rect_show_sub_phone')) $subFields[] = ['l' => $lbl('rect_lbl_sub_phone', 'الهاتف'), 'v' => $subPhoneValue, 'is_ltr' => true];

        if($chk('rect_show_square')) $subFields[] = ['l' => $lbl('rect_lbl_square', 'المربع السكني'), 'v' => $payment->subscriber->square->name ?? '-'];
        if($chk('rect_show_account_no')) $subFields[] = ['l' => $lbl('rect_lbl_account_no', 'رقم الحساب'), 'v' => $payment->subscriber_id, 'is_ltr' => true];

        $subChunks = array_chunk($subFields, 2);

        // 3. Payment Info Array
        $paymentFields = [];
        $paymentMethodValue = '';
        switch($payment->payment_method) {
        case 'cash': $paymentMethodValue = 'نقدي'; break;
        case 'bank_transfer': $paymentMethodValue = 'تحويل بنكي'; break;
        case 'check': $paymentMethodValue = 'شيك'; break;
        }
        if($chk('rect_show_linked_invoice')) $paymentFields[] = ['l' => $lbl('rect_lbl_linked_invoice', 'الفاتورة المرتبطة'), 'v' => $payment->invoice ? $payment->invoice->bill_number : '-', 'is_ltr' => true];

        if($chk('rect_show_amount_received')) {
        $formattedAmount = floor($payment->amount) == $payment->amount ? number_format($payment->amount, 0) : number_format($payment->amount, 2);
        $paymentFields[] = ['l' => $lbl('rect_lbl_amount_received', 'المبلغ المستلم'), 'v' => '<span style="color: #059669; font-weight: 800; font-size: 15px;">' . $formattedAmount . '</span>', 'is_ltr' => true];
        }

        if($chk('rect_show_amount_in_words')) {
        $amountInWords = class_exists('NumberFormatter') ? (new NumberFormatter('ar', NumberFormatter::SPELLOUT))->format($payment->amount) : '';
        $replacements = [
        'ثلاثة مائة' => 'ثلاث مائة',
        'أربعة مائة' => 'أربع مائة',
        'خمسة مائة' => 'خمس مائة',
        'ستة مائة' => 'ست مائة',
        'سبعة مائة' => 'سبع مائة',
        'ثمانية مائة' => 'ثمان مائة',
        'تسعة مائة' => 'تسع مائة',
        'اثنان مائة' => 'مائتان',
        'اثنتا مائة' => 'مائتان',
        ];
        $amountInWords = str_replace(array_keys($replacements), array_values($replacements), $amountInWords);

        $paymentFields[] = ['l' => $lbl('rect_lbl_amount_in_words', 'المبلغ المستلم كتابتا'), 'v' => '<span style="color: #0b2b40; font-weight: 600; font-size: 12px;">' . $amountInWords . ' ' . $lbl('rect_amount_words_suffix', 'ريال يمني لا غير') . '</span>', 'colspan' => 3];
        }

        if($chk('rect_show_payment_method')) $paymentFields[] = ['l' => $lbl('rect_lbl_payment_method', 'طريقة الدفع'), 'v' => $paymentMethodValue];

        if($chk('rect_show_reference') && $payment->reference_number) {
        $paymentFields[] = ['l' => $lbl('rect_lbl_reference', 'رقم الحوالة / الشيك'), 'v' => $payment->reference_number];
        }

        if($chk('rect_show_reason') && $payment->reason) {
        $paymentFields[] = ['l' => $lbl('rect_lbl_reason', 'وذلك مقابل'), 'v' => $payment->reason, 'colspan' => 3];
        }

        // Custom chunking logic for payment info due to colspan
        $paymentChunks = [];
        $currentChunk = [];
        $currentCols = 0;
        foreach($paymentFields as $field) {
        $cols = isset($field['colspan']) ? 2 : 1;
        if ($currentCols + $cols > 2) {
        $paymentChunks[] = $currentChunk;
        $currentChunk = [];
        $currentCols = 0;
        }
        $currentChunk[] = $field;
        $currentCols += $cols;
        }
        if (!empty($currentChunk)) {
        $paymentChunks[] = $currentChunk;
        }

        @endphp

        <div class="page-wrapper">
            {{-- Watermark --}}
            @if($watermarkBase64)
            <div class="watermark">
                <img src="{{ $watermarkBase64 }}" alt="Watermark">
            </div>
            @endif

            <div class="content">
                {{-- ===== HEADER ===== --}}
                <div class="header">
                    <div class="header-right">
                        <div class="project-name-ar">{{ \App\Models\Setting::get('invoice_project_name_ar', 'مشروع مياه الوشاح') }}</div>
                        <div class="header-detail">العنوان : {{ \App\Models\Setting::get('invoice_address_ar', 'حارة الفوارس – سعوان') }}</div>
                        <div class="header-detail">الهاتف : <span dir="ltr">{{ $formatPhone(\App\Models\Setting::get('invoice_phone_1', '712271220')) }}</span> - <span dir="ltr">{{ $formatPhone(\App\Models\Setting::get('invoice_phone_2', '777760193')) }}</span></div>
                    </div>

                    <div class="header-center">
                        @if($logoBase64)
                        <img src="{{ $logoBase64 }}" alt="Logo" class="logo-img">
                        @endif
                    </div>

                    <div class="header-left">
                        <div class="project-name-en">{{ \App\Models\Setting::get('invoice_project_name_en', 'Washah Water Project') }}</div>
                        <div class="header-detail">Address : {{ \App\Models\Setting::get('invoice_address_en', 'Harat Al-Fawaris - Sa\'wan') }}</div>
                        <div class="header-detail">Tel : <span dir="ltr">{{ $formatPhone(\App\Models\Setting::get('invoice_phone_1', '712271220')) }}</span> - <span dir="ltr">{{ $formatPhone(\App\Models\Setting::get('invoice_phone_2', '777760193')) }}</span></div>
                    </div>
                </div>

                {{-- ===== TITLE ===== --}}
                <div class="title-container">
                    <div class="title-line"></div>
                    <div class="title-text">{{ $lbl('rect_title_main', 'سند قبض مالي') }}</div>
                </div>

                {{-- ===== RECEIPT DETAILS ===== --}}
                @if(count($receiptInfoChunks) > 0 || count($subChunks) > 0 || count($paymentChunks) > 0)
                <table class="rounded-table">
                    @foreach($receiptInfoChunks as $chunk)
                    <tr>
                        @foreach($chunk as $idx => $field)
                        @php
                        $colspan = 1;
                        if ($loop->last && count($chunk) < 2) {
                            $remainingPairs=2 - count($chunk);
                            $colspan=1 + ($remainingPairs * 2);
                            }
                            @endphp
                            <td class="label-cell">{{ $field['l'] }}</td>
                            <td class="value-cell" colspan="{{ $colspan }}" @if(!empty($field['is_ltr'])) dir="ltr" @endif>{!! $field['v'] !!}</td>
                            @endforeach
                    </tr>
                    @endforeach

                    @if(count($subChunks) > 0)
                    @if($chk('rect_show_title_subscriber'))
                    <tr>
                        <td colspan="4" class="section-title">{{ $lbl('rect_title_subscriber', 'بيانات المشترك') }}</td>
                    </tr>
                    @endif
                    @foreach($subChunks as $chunk)
                    <tr>
                        @foreach($chunk as $idx => $field)
                        @php
                        $colspan = 1;
                        if ($loop->last && count($chunk) < 2) {
                            $remainingPairs=2 - count($chunk);
                            $colspan=1 + ($remainingPairs * 2);
                            }
                            @endphp
                            <td class="label-cell">{{ $field['l'] }}</td>
                            <td class="value-cell" colspan="{{ $colspan }}" @if(!empty($field['is_ltr'])) dir="ltr" @endif>{!! $field['v'] !!}</td>
                            @endforeach
                    </tr>
                    @endforeach
                    @endif

                    @if(count($paymentChunks) > 0)
                    @if($chk('rect_show_title_payment'))
                    <tr>
                        <td colspan="4" class="section-title">{{ $lbl('rect_title_payment', 'بيانات الدفع') }}</td>
                    </tr>
                    @endif
                    @foreach($paymentChunks as $chunk)
                    <tr>
                        @foreach($chunk as $idx => $field)
                        @php
                        $colspan = $field['colspan'] ?? 1;
                        if ($loop->last && count($chunk) < 2 && !isset($field['colspan'])) {
                            $remainingPairs=2 - count($chunk);
                            $colspan=1 + ($remainingPairs * 2);
                            }
                            @endphp
                            <td class="label-cell">{{ $field['l'] }}</td>
                            <td class="value-cell" colspan="{{ $colspan }}" @if(!empty($field['is_ltr'])) dir="ltr" @endif>{!! $field['v'] !!}</td>
                            @endforeach
                    </tr>
                    @endforeach
                    @endif
                </table>
                @endif

                {{-- ===== AMOUNTS STRIP ===== --}}
                @php
                    $showAmountStrip = ($payment->invoice && $chk('rect_show_invoice_total')) ||
                                       ($payment->invoice && $paidBefore > 0 && $chk('rect_show_prev_payments')) ||
                                       ($invoiceRemaining > 0 && $chk('rect_show_remaining_invoice')) ||
                                       ($outstanding > 0 && $chk('rect_show_outstanding_total')) ||
                                       ($creditBalance > 0 && $chk('rect_show_credit_balance'));
                @endphp
                @if($showAmountStrip)
                <style>
                    .amount-strip {
                        display: flex;
                        width: 100%;
                        border-radius: 8px;
                        overflow: hidden;
                        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
                        margin-top: 10px;
                        border: 1px solid rgba(0, 0, 0, 0.1);
                    }

                    .amount-section {
                        flex: 1;
                        display: flex;
                        flex-direction: column;
                        text-align: center;
                    }

                    .amount-section+.amount-section {
                        border-right: 1px solid rgba(255, 255, 255, 1);
                    }
                </style>
                <div class="amount-strip">
                    @if($payment->invoice && $chk('rect_show_invoice_total'))
                    <div class="amount-section">
                        <div style="background: linear-gradient(to bottom, #19528e, #0f3057); color: #fff; padding: 10px 5px; font-weight: 800; font-size: 13px;">{{ $lbl('rect_lbl_invoice_total', 'إجمالي الفاتورة') }}</div>
                        <div style="background: rgba(228, 236, 239, 0.4); color: #19528e; padding: 5px 5px; font-weight: 800; font-size: 17px;" dir="ltr">{{ number_format($invoiceTotal, 2) }}</div>
                    </div>
                    @endif

                    @if($payment->invoice && $paidBefore > 0 && $chk('rect_show_prev_payments'))
                    <div class="amount-section">
                        <div style="background: linear-gradient(to bottom, #19528e, #0f3057); color: #fff; padding: 10px 5px; font-weight: 800; font-size: 13px;">{{ $lbl('rect_lbl_prev_payments', 'مدفوعات سابقة') }}</div>
                        <div style="background: rgba(254, 243, 199, 0.4); color: #b45309; padding: 5px 5px; font-weight: 800; font-size: 17px;" dir="ltr">{{ number_format($paidBefore, 2) }}</div>
                    </div>
                    @endif

                    @if($invoiceRemaining > 0 && $chk('rect_show_remaining_invoice'))
                    <div class="amount-section">
                        <div style="background: linear-gradient(to bottom, #19528e, #0f3057); color: #fff; padding: 10px 5px; font-weight: 800; font-size: 13px;">{{ $lbl('rect_lbl_remaining_invoice', 'المتبقي من الفاتورة') }}</div>
                        <div style="background: rgba(255, 237, 213, 0.4); color: #c2410c; padding: 5px 5px; font-weight: 800; font-size: 17px;" dir="ltr">{{ number_format($invoiceRemaining, 2) }}</div>
                    </div>
                    @endif

                    @if($outstanding > 0 && $chk('rect_show_outstanding_total'))
                    <div class="amount-section">
                        <div style="background: linear-gradient(to bottom, #19528e, #0f3057); color: #fff; padding: 10px 5px; font-weight: 800; font-size: 13px;">{{ $lbl('rect_lbl_outstanding_total', 'إجمالي المتبقي للحساب') }}</div>
                        <div style="background: rgba(254, 226, 226, 0.4); color: #b91c1c; padding: 5px 5px; font-weight: 800; font-size: 17px;" dir="ltr">{{ number_format($outstanding, 2) }}</div>
                    </div>
                    @endif

                    @if($creditBalance > 0 && $chk('rect_show_credit_balance'))
                    <div class="amount-section">
                        <div style="background: linear-gradient(to bottom, #19528e, #0f3057); color: #fff; padding: 10px 5px; font-weight: 800; font-size: 13px;">{{ $lbl('rect_lbl_credit_balance', 'رصيد دائن للمشترك') }}</div>
                        <div style="background: rgba(224, 242, 254, 0.4); color: #0284c7; padding: 5px 5px; font-weight: 800; font-size: 17px;" dir="ltr">{{ number_format($creditBalance, 2) }}</div>
                    </div>
                    @endif
                </div>
                @endif

                {{-- ===== COLLECTOR ===== --}}
                @if($chk('rect_show_collector_sig'))
                <div style="padding-right: 15px; margin-top: 10px;">
                    <span style="font-weight: 600; font-size: 12px; color: #0b2b40;">{{ $lbl('rect_lbl_collector_sig', 'المحصل') }} : {{ $payment->user ? $payment->user->name : \App\Models\Setting::get('invoice_collector_name', 'علي الوشاح') }}</span>
                </div>
                @endif

                {{-- ===== FOOTER ===== --}}
                @if(($payment->notes && $chk('rect_show_notes')) || $chk('rect_show_receiver_sig'))
                <div class="footer-box">
                    <div class="notes" style="width: 100%;">
                        @if($payment->notes && $chk('rect_show_notes'))
                        <strong>{{ $lbl('rect_lbl_notes', 'ملاحظات') }}:</strong> {{ $payment->notes }}
                        @endif
                    </div>
                    @if($chk('rect_show_receiver_sig'))
                    <div class="collector" style="min-width: 150px; text-align: left;">
                        {{ $lbl('rect_lbl_receiver_sig', 'توقيع المستلم') }}
                    </div>
                    @endif
                </div>
                @endif
            </div>
        </div>
</body>

</html>

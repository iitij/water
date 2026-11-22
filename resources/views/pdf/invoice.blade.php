<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>{{ \App\Models\Setting::get('inv_title_main', 'فاتورة استهلاك مياه') }}</title>
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
            font-size: 10px;
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
            /* Semi-transparent */
            table-layout: fixed;
        }

        .rounded-table td {
            border: 0.5px solid rgba(40, 55, 61, 0.5);
            border-left: none;
            border-bottom: none;
            text-align: center;
            padding: 6px 6px;
            font-size: 11px;
            color: #0b2b40;
            vertical-align: middle;
            font-weight: 500;
            word-wrap: break-word;
        }

        /* Remove double borders inside the rounded wrapper */
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
            width: 14%;
        }

        .label-cell-two {
            background: linear-gradient(to bottom, rgba(152, 214, 240, 0.23), rgba(115, 184, 218, 0.75));
            font-weight: 800 !important;
            font-size: 13px;
            color: #0b2b40;
        }

        .value-cell {
            background: rgba(255, 255, 255, 0.6);
            font-weight: 700 !important;
            font-size: 11px;
            width: 19.33%;
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
        }

        .due-box {
            display: flex;
            margin-top: 4px;
            border: 1px solid #19528e;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 2px 2px 5px rgba(0, 0, 0, 0.15);
        }

        .due-label {
            background: linear-gradient(to bottom, rgba(25, 82, 142, 0.85), rgba(15, 48, 87, 0.85));
            color: #fff;
            padding: 8px 25px;
            font-weight: 800;
            font-size: 14px;
            display: flex;
            align-items: center;
        }

        .due-value {
            background: rgba(228, 236, 239, 0.6);
            color: #000;
            padding: 8px 25px;
            font-weight: 800;
            font-size: 16px;
            display: flex;
            align-items: center;
            min-width: 140px;
            justify-content: center;
        }

        /* ===== FOOTER ===== */
        .footer-box {
            border: 1px solid rgba(40, 55, 61, 0.5);
            margin-top: 4px;
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
        }

        .footer-box .signature {
            font-weight: 800;
            font-size: 14px;
            color: #0b2b40;
            direction: rtl;
        }

        .thank-you {
            text-align: center;
            margin-top: 12px;
            font-size: 12px;
            font-weight: 800;
            color: #2e5970;
        }

        /* ===== WATERMARK ===== */
        .watermark {
            position: absolute;
            top: 55%;
            left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.4;
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
    use App\Models\Setting;

    // Use only invoice-owned readings. A late entry date may fall outside
    // the consumption period, and unrelated meter readings are not evidence.
    $readings = $invoice->readings;
    $sortedReadings = $readings->sortBy(
        fn ($reading) => sprintf('%s|%s|%010d', $reading->period_start, $reading->period_end, $reading->id)
    );
    $firstReading = $sortedReadings->first();
    $lastReading = $sortedReadings->last();

    $previous_reading = $firstReading?->previous_reading;
    $current_reading = $lastReading?->current_reading;
            $consumption = $readings->sum('consumption_units');

            $price_per_unit = $consumption > 0 ? $invoice->consumption_fee / $consumption : null;

            // Logo
            $logoPath = Setting::get('invoice_logo', null);
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
            $watermarkPath = Setting::get('invoice_watermark', null);
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
            } else {
            $watermarkBase64 = $logoBase64;
            }


            // DYNAMIC CONFIGURATION
            $chk = function($key) { return Setting::get($key, '1') == '1'; };
            $lbl = function($key, $default) { return Setting::get($key, $default); };

            $mainTitle = $lbl('inv_title_main', 'فاتورة استهلاك مياه');
            $detailsTitle = $lbl('inv_title_consumption', 'تفاصيل الاستهلاك');
            $showNotes = $chk('inv_show_notes');
            $notesLabel = $lbl('inv_lbl_notes', 'الملاحظات');

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

            // 1. Invoice Info Array
            $infoFields = [];
            if($chk('inv_show_bill_number')) $infoFields[] = ['l' => $lbl('inv_lbl_bill_number', 'رقم الفاتورة'), 'v' => preg_replace('/[^0-9]/', '', $invoice->bill_number), 'is_ltr' => true];
            
                if($chk('inv_show_date_from')) $infoFields[] = ['l' => $lbl('inv_lbl_date_from', 'من تاريخ'), 'v' => ($invoice->billing_period_start?->format('d/m/Y') ?? '—')];
                if($chk('inv_show_date_to')) $infoFields[] = ['l' => $lbl('inv_lbl_date_to', 'إلى تاريخ'), 'v' => ($invoice->billing_period_end?->format('d/m/Y') ?? '—')];
            $infoChunks = array_chunk($infoFields, 3);

            // 2. Subscriber Info Array
            $subFields = [];
            if($chk('inv_show_sub_name')) $subFields[] = ['l' => $lbl('inv_lbl_sub_name', 'اسم المشترك'), 'v' => $invoice->subscriber->name];
            if($chk('inv_show_meter_no')) $subFields[] = ['l' => $lbl('inv_lbl_meter_no', 'رقم العداد'), 'v' => $invoice->meter?->unique_code ?? $invoice->subscriber->currentMeter?->unique_code ?? '-', 'is_ltr' => true];
            if($chk('inv_show_sub_phone')) $subFields[] = ['l' => $lbl('inv_lbl_sub_phone', 'الهاتف'), 'v' => $formatPhone($invoice->subscriber->phone ?? '-'), 'is_ltr' => true];

            if($chk('inv_show_square')) $subFields[] = ['l' => $lbl('inv_lbl_square', 'المربع'), 'v' => $invoice->subscriber->square->name ?? '-'];
            if($chk('inv_show_address')) $subFields[] = ['l' => $lbl('inv_lbl_address', 'العنوان'), 'v' => $invoice->subscriber->address ?? '-'];
            if($chk('inv_show_account_no')) $subFields[] = ['l' => $lbl('inv_lbl_account_no', 'رقم الحساب'), 'v' => $invoice->subscriber_id, 'is_ltr' => true];

            $subChunks = array_chunk($subFields, 2);

            // 3. Details Headers & Values
            $consHeaders = [];
            $consValues = [];


                if($chk('inv_show_prev_read')) {
                $consHeaders[] = $lbl('inv_lbl_prev_read', 'القراءة السابقة');
                $consValues[] = $previous_reading !== null ? number_format($previous_reading, 2) : '—';
                }
                if($chk('inv_show_curr_read')) {
                $consHeaders[] = $lbl('inv_lbl_curr_read', 'القراءة الحالية');
                $consValues[] = $current_reading !== null ? number_format($current_reading, 2) : '—';
                }
                if($chk('inv_show_units')) {
                $consHeaders[] = $lbl('inv_lbl_units', 'وحدات الاستهلاك');
                $consValues[] = $readings->isNotEmpty() ? '<strong style="font-size:12px;">' . $consumption . '</strong>' : '—';
                }
                if($chk('inv_show_unit_price')) {
                $consHeaders[] = $lbl('inv_lbl_unit_price', 'سعر الوحدة');
                $consValues[] = $price_per_unit !== null ? number_format($price_per_unit, 2) : '—';
                }
                if($chk('inv_show_cons_value')) {
                $consHeaders[] = $lbl('inv_lbl_cons_value', 'قيمة الاستهلاك');
                $consValues[] = '<strong style="font-size:12px;">' . number_format($invoice->consumption_fee, 2) . '</strong>';
                }
                if($chk('inv_show_arrears')) {
                $consHeaders[] = $lbl('inv_lbl_arrears', 'المتأخرات');
                $consValues[] = number_format($invoice->previous_balance, 2);
                }

                if($invoice->credit_applied > 0 && $chk('inv_show_credit_applied')) {
                $consHeaders[] = $lbl('inv_lbl_credit_applied', 'رصيد مخصوم');
                $consValues[] = '<strong style="color: #059669; font-size:12px;">' . number_format($invoice->credit_applied, 2) . '</strong>';
                }

            @endphp

            <div class="page-wrapper">
                {{-- ===== WATERMARK ===== --}}
                @if($watermarkBase64)
                <div class="watermark">
                    <img src="{{ $watermarkBase64 }}" alt="">
                </div>
                @endif

                {{-- ===== MAIN CONTENT ===== --}}
                <div class="content">

                    {{-- ===== HEADER ===== --}}
                    <div class="header">
                        <div class="header-right">
                            <div class="project-name-ar">{{ Setting::get('invoice_project_name_ar', 'مشروع مياه الوشاح') }}</div>
                            <div class="header-detail">العنوان : {{ Setting::get('invoice_address_ar', 'حارة الفوارس - سعوان') }}</div>
                            <div class="header-detail">الهاتف : <span dir="ltr">{{ $formatPhone(Setting::get('invoice_phone_1', '1234567890')) }}</span> – <span dir="ltr">{{ $formatPhone(Setting::get('invoice_phone_2', '1234567890')) }}</span></div>
                        </div>
                        <div class="header-center">
                            @if($logoBase64)
                            <img src="{{ $logoBase64 }}" alt="Logo" class="logo-img">
                            @endif
                        </div>
                        <div class="header-left">
                            <div class="project-name-en">{{ Setting::get('invoice_project_name_en', 'Washah Water Project') }}</div>
                            <div class="header-detail">Address : {{ Setting::get('invoice_address_en', 'Harat Al-Fawaris - Sa\'wan') }}</div>
                            <div class="header-detail">Tel : <span dir="ltr">{{ $formatPhone(Setting::get('invoice_phone_1', '1234567890')) }}</span> – <span dir="ltr">{{ $formatPhone(Setting::get('invoice_phone_2', '1234567890')) }}</span></div>
                        </div>
                    </div>

                    {{-- ===== TITLE ===== --}}
                    <div class="title-container">
                        <div class="title-line"></div>
                        <div class="title-text">{{ $mainTitle }}</div>
                    </div>

                    {{-- ===== STRIP 1: Invoice Info ===== --}}
                    @if(count($infoChunks) > 0)
                    <table class="rounded-table">
                        <colgroup>
                            @for($i=0; $i<count($infoChunks[0]); $i++)
                            <col width="15%">
                            <col width="{{ (100 / count($infoChunks[0])) - 15 }}%">
                            @endfor
                        </colgroup>
                        @foreach($infoChunks as $chunk)
                        <tr>
                            @foreach($chunk as $idx => $field)
                            @php
                            // If this is the last item in the chunk and there are less than 3 items, span the rest of the row
                            $colspan = 1;
                            if ($loop->last && count($chunk) < count($infoChunks[0])) {
                                $remainingPairs = count($infoChunks[0]) - count($chunk);
                                $colspan = 1 + ($remainingPairs * 2); // each pair is 2 cols
                            }
                            @endphp
                            <td class="label-cell">{{ $field['l'] }}</td>
                            <td class="value-cell" colspan="{{ $colspan }}" @if(!empty($field['is_ltr'])) dir="ltr" @endif>{{ $field['v'] }}</td>
                            @endforeach
                        </tr>
                        @endforeach
                    </table>
                    @endif

                    {{-- ===== SUBSCRIBER INFO ===== --}}
                    @if(count($subChunks) > 0)
                    <table class="rounded-table">
                        <colgroup>
                            @for($i=0; $i<count($subChunks[0]); $i++)
                            <col width="15%">
                            <col width="{{ (100 / count($subChunks[0])) - 15 }}%">
                            @endfor
                        </colgroup>
                        <tr>
                            <td colspan="{{ count($subChunks[0]) * 2 }}" class="section-title">{{ $lbl('inv_title_subscriber', 'بيانات المشترك') }}</td>
                        </tr>
                        @foreach($subChunks as $chunk)
                        <tr>
                            @foreach($chunk as $idx => $field)
                            @php
                            $colspan = 1;
                            if ($loop->last && count($chunk) < count($subChunks[0])) {
                                $remainingPairs = count($subChunks[0]) - count($chunk);
                                $colspan = 1 + ($remainingPairs * 2);
                            }
                            @endphp
                            <td class="label-cell">{{ $field['l'] }}</td>
                            <td class="value-cell" colspan="{{ $colspan }}" @if(!empty($field['is_ltr'])) dir="ltr" @endif>{{ $field['v'] }}</td>
                                @endforeach
                        </tr>
                        @endforeach
                    </table>
                    @endif

                    {{-- ===== CONSUMPTION DETAILS ===== --}}
                    @if(count($consHeaders) > 0)
                    <table class="rounded-table">
                        <tr>
                            <td colspan="{{ count($consHeaders) }}" class="section-title">{{ $detailsTitle }}</td>
                        </tr>
                        <tr>
                            @foreach($consHeaders as $header)
                            <td class="label-cell-two" width="{{ 100 / count($consHeaders) }}%">{{ $header }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            @foreach($consValues as $value)
                            <td class="value-cell">{!! $value !!}</td>
                            @endforeach
                        </tr>
                    </table>
                    @endif

                    {{-- ===== TOTAL AMOUNT ===== --}}
                    <div class="bottom-row" style="justify-content: space-between; align-items: center; margin-top: 6px;">
                        <div style="padding-right: 15px;">
                            @if($chk('inv_show_collector'))
                            <span style="font-weight: 800; font-size: 15px; color: #0b2b40;">{{ $lbl('inv_lbl_collector', 'المحصل') }} : {{ auth()->user() ? auth()->user()->name : \App\Models\Setting::get('invoice_collector_name', 'علي الوشاح') }}</span>
                            @endif
                        </div>
                        <div class="due-box" style="margin-top: 0;">
                            <div class="due-label">{{ $lbl('inv_title_total', 'المبلغ المستحق') }}</div>
                            <div class="due-value" style="direction: ltr;">{{ number_format(($invoice->total_amount + $invoice->previous_balance) - $invoice->credit_applied, 2) }}</div>
                        </div>
                    </div>

                    {{-- ===== NOTES ===== --}}
                    @if($invoice->notes && $showNotes)
                    <div style="margin-top: 8px; border: 1px dashed rgba(40, 55, 61, 0.5); padding: 8px 15px; border-radius: 8px; background: rgba(255, 255, 255, 0.7); font-size: 11px;">
                        <strong style="color: #124c6b;">{{ $notesLabel }}:</strong> {{ $invoice->notes }}
                    </div>
                    @endif

                    {{-- ===== FOOTER ===== --}}
                    @if($chk('inv_show_signature'))
                    <div class="footer-box" style="justify-content: flex-end;">
                        <div class="signature">{{ $lbl('inv_lbl_signature', 'توقيع المشترك') }} : ................................</div>
                    </div>
                    @endif

                    {{-- ===== THANK YOU ===== --}}
                    @if($chk('inv_show_thank_you'))
                    <div class="thank-you">
                        {{ $lbl('inv_lbl_thank_you', 'شكراً لدفعكم في الموعد / Thank you for your timely payment') }}
                    </div>
                    @endif

                </div>

</body>

</html>

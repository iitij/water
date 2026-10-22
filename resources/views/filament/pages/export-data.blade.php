<x-filament-panels::page>
    <style>
        .export-center {
            display: flex;
            flex-direction: column;
            gap: 24px;
            font-family: inherit;
            direction: rtl;
        }

        /* Hero Banner */
        .export-hero {
            background: linear-gradient(135deg, #d97706 0%, #b45309 50%, #92400e 100%);
            border-radius: 16px;
            padding: 22px 26px;
            color: #ffffff;
            box-shadow: 0 10px 25px -5px rgba(217, 119, 6, 0.3);
            display: flex;
            flex-direction: column;
            gap: 16px;
            position: relative;
            overflow: hidden;
        }

        @media (min-width: 768px) {
            .export-hero {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .export-hero-title {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .export-hero-icon {
            width: 48px;
            height: 48px;
            min-width: 48px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .export-hero-icon svg {
            width: 26px !important;
            height: 26px !important;
            max-width: 26px !important;
            max-height: 26px !important;
            color: #ffffff;
        }

        .export-hero h2 {
            font-size: 1.35rem;
            font-weight: 800;
            margin: 0;
            color: #ffffff;
        }

        .export-hero p {
            font-size: 0.85rem;
            color: rgba(254, 243, 199, 0.9);
            margin: 4px 0 0 0;
        }

        .export-stats-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .export-stat-box {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 12px;
            padding: 8px 16px;
            text-align: center;
            min-width: 100px;
        }

        .export-stat-label {
            display: block;
            font-size: 0.75rem;
            color: rgba(254, 243, 199, 0.95);
        }

        .export-stat-value {
            display: block;
            font-size: 1.25rem;
            font-weight: 800;
            color: #ffffff;
        }

        /* 3 Cards Grid */
        .export-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 24px;
        }

        @media (min-width: 1024px) {
            .export-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .export-card {
            background: #18181b;
            border: 1px solid #27272a;
            border-radius: 16px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease-in-out;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
        }

        /* Light mode support */
        :not(.dark) .export-card {
            background: #ffffff;
            border-color: #e5e7eb;
        }

        .export-card:hover {
            border-color: #3f3f46;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2);
        }

        :not(.dark) .export-card:hover {
            border-color: #cbd5e1;
        }

        .export-card-header {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-bottom: 14px;
            border-bottom: 1px solid #27272a;
        }

        :not(.dark) .export-card-header {
            border-bottom-color: #f1f5f9;
        }

        .export-card-icon {
            width: 44px;
            height: 44px;
            min-width: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .export-card-icon svg {
            width: 24px !important;
            height: 24px !important;
            max-width: 24px !important;
            max-height: 24px !important;
        }

        .export-card-icon.blue {
            background: rgba(37, 99, 235, 0.15);
            color: #60a5fa;
        }

        .export-card-icon.green {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
        }

        .export-card-icon.cyan {
            background: rgba(6, 182, 212, 0.15);
            color: #22d3ee;
        }

        .export-card-title h3 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #f4f4f5;
            margin: 0;
        }

        :not(.dark) .export-card-title h3 {
            color: #111827;
        }

        .export-card-title p {
            font-size: 0.78rem;
            color: #a1a1aa;
            margin: 2px 0 0 0;
        }

        .export-card-desc {
            font-size: 0.8rem;
            color: #94a3b8;
            line-height: 1.5;
            margin: 12px 0 16px 0;
        }

        /* Filter inputs */
        .export-filters {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .export-filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .export-filter-group label {
            font-size: 0.78rem;
            font-weight: 600;
            color: #d4d4d8;
        }

        :not(.dark) .export-filter-group label {
            color: #374151;
        }

        .export-select, .export-input {
            width: 100%;
            border-radius: 8px;
            border: 1px solid #3f3f46;
            background-color: #27272a;
            color: #f4f4f5;
            padding: 8px 12px;
            font-size: 0.85rem;
            outline: none;
            transition: border-color 0.15s;
            direction: rtl;
            box-sizing: border-box;
        }

        :not(.dark) .export-select, :not(.dark) .export-input {
            background-color: #ffffff;
            border-color: #d1d5db;
            color: #111827;
        }

        .export-select:focus, .export-input:focus {
            border-color: #f59e0b;
            box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.2);
        }

        .export-two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        /* Export Buttons & Actions */
        .export-card-actions {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #27272a;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        :not(.dark) .export-card-actions {
            border-top-color: #f1f5f9;
        }

        .export-btn {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 700;
            color: #ffffff;
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            transition: all 0.15s ease-in-out;
            box-sizing: border-box;
        }

        .export-btn svg {
            width: 18px !important;
            height: 18px !important;
            min-width: 18px !important;
            max-width: 18px !important;
            color: #ffffff;
        }

        .export-btn.blue {
            background: #2563eb;
        }
        .export-btn.blue:hover {
            background: #1d4ed8;
        }

        .export-btn.green {
            background: #059669;
        }
        .export-btn.green:hover {
            background: #047857;
        }

        .export-btn.cyan {
            background: #0891b2;
        }
        .export-btn.cyan:hover {
            background: #0e7490;
        }

        .export-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .export-direct-link {
            display: block;
            text-align: center;
            font-size: 0.78rem;
            text-decoration: underline;
            transition: opacity 0.15s;
        }

        .export-direct-link.blue { color: #60a5fa; }
        .export-direct-link.green { color: #34d399; }
        .export-direct-link.cyan { color: #22d3ee; }

        .export-direct-link:hover {
            opacity: 0.8;
        }

        /* Spinner */
        .export-spinner {
            width: 18px !important;
            height: 18px !important;
            max-width: 18px !important;
            max-height: 18px !important;
            animation: export-spin 1s linear infinite;
        }

        @keyframes export-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Info Note */
        .export-note {
            background: #27272a;
            border: 1px solid #3f3f46;
            border-radius: 12px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.8rem;
            color: #a1a1aa;
        }

        :not(.dark) .export-note {
            background: #f8fafc;
            border-color: #e2e8f0;
            color: #64748b;
        }

        .export-note svg {
            width: 22px !important;
            height: 22px !important;
            min-width: 22px !important;
            max-width: 22px !important;
            color: #f59e0b;
        }
    </style>

    <div class="export-center">
        <!-- Hero Header Banner -->
        <div class="export-hero">
            <div class="export-hero-title">
                <div class="export-hero-icon">
                    <x-heroicon-o-arrow-down-tray />
                </div>
                <div>
                    <h2>مركز تصدير البيانات إلى Excel</h2>
                    <p>تنزيل تقارير وقوائم النظام بصيغة XLSX القياسية المتوافقة مع جداول Microsoft Excel</p>
                </div>
            </div>

            <!-- Stats Badges -->
            <div class="export-stats-row">
                <div class="export-stat-box">
                    <span class="export-stat-label">إجمالي المشتركين</span>
                    <span class="export-stat-value">{{ number_format($totalSubscribers) }}</span>
                </div>
                <div class="export-stat-box">
                    <span class="export-stat-label">إجمالي الفواتير</span>
                    <span class="export-stat-value">{{ number_format($totalInvoices) }}</span>
                </div>
                <div class="export-stat-box">
                    <span class="export-stat-label">إجمالي القراءات</span>
                    <span class="export-stat-value">{{ number_format($totalReadings) }}</span>
                </div>
            </div>
        </div>

        <!-- 3 Main Export Cards Grid -->
        <div class="export-grid">

            <!-- Card 1: Subscribers Export -->
            <div class="export-card">
                <div>
                    <div class="export-card-header">
                        <div class="export-card-icon blue">
                            <x-heroicon-o-users />
                        </div>
                        <div class="export-card-title">
                            <h3>قائمة المشتركين</h3>
                            <p>بيانات المشتركين، العدادات، والأرصدة</p>
                        </div>
                    </div>

                    <p class="export-card-desc">
                        يتضمن الملف: الاسم، الهاتف، المربع، العنوان، كود العداد، المسار، المستأجر، الرصيد الدائن والمدين، رسوم الاشتراك وتاريخ التسجيل.
                    </p>

                    <!-- Filters -->
                    <div class="export-filters">
                        <div class="export-filter-group">
                            <label>تصفية بحسب المربع السكني</label>
                            <select wire:model.live="subscribers_square_id" class="export-select">
                                <option value="">جميع المربعات</option>
                                @foreach($squares as $sq)
                                    <option value="{{ $sq->id }}">{{ $sq->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="export-filter-group">
                            <label>حالة المشترك</label>
                            <select wire:model.live="subscribers_status" class="export-select">
                                <option value="">جميع الحالات</option>
                                <option value="active">نشط فقط</option>
                                <option value="inactive">غير نشط</option>
                                <option value="suspended">موقوف</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Export Action -->
                <div class="export-card-actions">
                    <button type="button"
                            wire:click="exportSubscribers"
                            wire:loading.attr="disabled"
                            wire:target="exportSubscribers"
                            class="export-btn blue">
                        <span wire:loading.remove wire:target="exportSubscribers" style="display: flex; align-items: center; gap: 8px;">
                            <x-heroicon-o-arrow-down-tray />
                            تنزيل ملف المشتركين (Excel)
                        </span>
                        <span wire:loading wire:target="exportSubscribers" style="display: flex; align-items: center; gap: 8px;">
                            <svg class="export-spinner" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity="0.25"></circle>
                                <path fill="currentColor" opacity="0.75" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            جاري إنشاء ملف Excel...
                        </span>
                    </button>

                    <a href="{{ route('admin.export.subscribers', ['square_id' => $subscribers_square_id, 'status' => $subscribers_status]) }}"
                       target="_blank"
                       class="export-direct-link blue">
                        أو اضغط هنا للتحميل المباشر السريع
                    </a>
                </div>
            </div>

            <!-- Card 2: Invoices Export -->
            <div class="export-card">
                <div>
                    <div class="export-card-header">
                        <div class="export-card-icon green">
                            <x-heroicon-o-document-currency-dollar />
                        </div>
                        <div class="export-card-title">
                            <h3>فواتير المشتركين</h3>
                            <p>الاستهلاك، الرسوم، والمبالغ المسددة</p>
                        </div>
                    </div>

                    <p class="export-card-desc">
                        يتضمن الملف: رقم الفاتورة، التاريخ، المشترك، المستأجر، المربع، العداد، المسار، رسوم الاستهلاك، الأقساط، المتأخرات، المطلوب، والمسدد.
                    </p>

                    <!-- Filters -->
                    <div class="export-filters">
                        <div class="export-filter-group">
                            <label>تصفية بحسب المربع السكني</label>
                            <select wire:model.live="invoices_square_id" class="export-select">
                                <option value="">جميع المربعات</option>
                                @foreach($squares as $sq)
                                    <option value="{{ $sq->id }}">{{ $sq->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="export-two-col">
                            <div class="export-filter-group">
                                <label>من تاريخ</label>
                                <input type="date" wire:model.live="invoices_from_date" class="export-input" />
                            </div>
                            <div class="export-filter-group">
                                <label>إلى تاريخ</label>
                                <input type="date" wire:model.live="invoices_to_date" class="export-input" />
                            </div>
                        </div>

                        <div class="export-filter-group">
                            <label>حالة الفاتورة</label>
                            <select wire:model.live="invoices_status" class="export-select">
                                <option value="">جميع الحالات</option>
                                <option value="paid">مسددة بالكامل</option>
                                <option value="partially_paid">مسددة جزئياً</option>
                                <option value="issued">صادرة (مستحقة)</option>
                                <option value="overdue">متأخرة</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Export Action -->
                <div class="export-card-actions">
                    <button type="button"
                            wire:click="exportInvoices"
                            wire:loading.attr="disabled"
                            wire:target="exportInvoices"
                            class="export-btn green">
                        <span wire:loading.remove wire:target="exportInvoices" style="display: flex; align-items: center; gap: 8px;">
                            <x-heroicon-o-arrow-down-tray />
                            تنزيل فواتير المشتركين (Excel)
                        </span>
                        <span wire:loading wire:target="exportInvoices" style="display: flex; align-items: center; gap: 8px;">
                            <svg class="export-spinner" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity="0.25"></circle>
                                <path fill="currentColor" opacity="0.75" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            جاري إنشاء ملف Excel...
                        </span>
                    </button>

                    <a href="{{ route('admin.export.invoices', ['square_id' => $invoices_square_id, 'from_date' => $invoices_from_date, 'to_date' => $invoices_to_date, 'status' => $invoices_status]) }}"
                       target="_blank"
                       class="export-direct-link green">
                        أو اضغط هنا للتحميل المباشر السريع
                    </a>
                </div>
            </div>

            <!-- Card 3: Readings Export -->
            <div class="export-card">
                <div>
                    <div class="export-card-header">
                        <div class="export-card-icon cyan">
                            <x-heroicon-o-chart-bar />
                        </div>
                        <div class="export-card-title">
                            <h3>قراءات العدادات</h3>
                            <p>سجل القراءات والاستهلاك المعتمد</p>
                        </div>
                    </div>

                    <p class="export-card-desc">
                        يتضمن الملف: التاريخ، المشترك، كود العداد، المسار، القراءة السابقة، القراءة الحالية، كمية الاستهلاك، فترة الفوترة، واسم مسجل القراءة.
                    </p>

                    <!-- Filters -->
                    <div class="export-filters">
                        <div class="export-filter-group">
                            <label>تصفية بحسب المربع السكني</label>
                            <select wire:model.live="readings_square_id" class="export-select">
                                <option value="">جميع المربعات</option>
                                @foreach($squares as $sq)
                                    <option value="{{ $sq->id }}">{{ $sq->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="export-two-col">
                            <div class="export-filter-group">
                                <label>من تاريخ</label>
                                <input type="date" wire:model.live="readings_from_date" class="export-input" />
                            </div>
                            <div class="export-filter-group">
                                <label>إلى تاريخ</label>
                                <input type="date" wire:model.live="readings_to_date" class="export-input" />
                            </div>
                        </div>

                        <div class="export-filter-group">
                            <label>حالة الفوترة</label>
                            <select wire:model.live="readings_is_invoiced" class="export-select">
                                <option value="">جميع القراءات</option>
                                <option value="1">مفوترة فقط</option>
                                <option value="0">غير مفوترة (جديدة)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Export Action -->
                <div class="export-card-actions">
                    <button type="button"
                            wire:click="exportReadings"
                            wire:loading.attr="disabled"
                            wire:target="exportReadings"
                            class="export-btn cyan">
                        <span wire:loading.remove wire:target="exportReadings" style="display: flex; align-items: center; gap: 8px;">
                            <x-heroicon-o-arrow-down-tray />
                            تنزيل قراءات العدادات (Excel)
                        </span>
                        <span wire:loading wire:target="exportReadings" style="display: flex; align-items: center; gap: 8px;">
                            <svg class="export-spinner" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity="0.25"></circle>
                                <path fill="currentColor" opacity="0.75" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            جاري إنشاء ملف Excel...
                        </span>
                    </button>

                    <a href="{{ route('admin.export.readings', ['square_id' => $readings_square_id, 'from_date' => $readings_from_date, 'to_date' => $readings_to_date, 'is_invoiced' => $readings_is_invoiced]) }}"
                       target="_blank"
                       class="export-direct-link cyan">
                        أو اضغط هنا للتحميل المباشر السريع
                    </a>
                </div>
            </div>

        </div>

        <!-- Info Note Banner -->
        <div class="export-note">
            <x-heroicon-o-information-circle />
            <span>
                يتم إنشاء وتوليد ملفات Excel بسرعة فائقة بصيغة XLSX القياسية. يمكنك فتحها باستخدام Microsoft Excel أو برامج جداول البيانات الأخرى، وتكون مرتبة بعناوين واضحة وتنسيقات مناسبة للطباعة والتحليل المالي.
            </span>
        </div>
    </div>
</x-filament-panels::page>

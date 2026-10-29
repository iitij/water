<x-filament-panels::page>
    <style>
        .mobile-developer-tools { display: grid; gap: 16px; }
        .mobile-developer-tool { display: flex; align-items: flex-start; gap: 16px; padding: 20px; border: 1px solid #e5e7eb; border-radius: 12px; color: inherit; text-decoration: none; transition: border-color .15s, box-shadow .15s; }
        .mobile-developer-tool:hover { border-color: #d97706; box-shadow: 0 2px 8px rgb(0 0 0 / .06); }
        .mobile-developer-tool:focus-visible { outline: 2px solid #d97706; outline-offset: 4px; }
        .mobile-developer-tool-icon { width: 28px; height: 28px; flex-shrink: 0; color: #b45309; }
        .mobile-developer-tool-content { flex: 1; min-width: 0; }
        .mobile-developer-tool-title { font-weight: 600; }
        .mobile-developer-tool-description { margin-top: 6px; font-size: .875rem; line-height: 1.7; color: #6b7280; }
        .mobile-developer-tool-badge { display: inline-block; margin-inline-start: 6px; padding: 1px 8px; border-radius: 999px; background: #fef3c7; color: #92400e; font-size: .75rem; }
        .mobile-developer-tool-link { display: block; margin-top: 12px; font-size: .875rem; color: #b45309; }
        .mobile-change-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin: 16px 0; }
        .mobile-change-stat { padding: 16px; border-radius: 10px; background: #f9fafb; text-align: center; }
        .mobile-change-stat strong { display: block; font-size: 1.5rem; color: #b45309; }
        .mobile-change-stat span, .mobile-change-note { font-size: .875rem; line-height: 1.8; color: #6b7280; }
        .mobile-change-links { display: flex; flex-wrap: wrap; gap: 20px; margin-top: 12px; }
        .mobile-change-links a { color: #b45309; text-decoration: underline; text-underline-offset: 4px; }
        .mobile-change-list { display: grid; gap: 12px; margin-top: 16px; }
        .mobile-change-group { border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px; }
        .mobile-change-group summary { cursor: pointer; line-height: 1.8; }
        .mobile-change-group summary:focus-visible { outline: 2px solid #d97706; outline-offset: 4px; }
        .mobile-change-group summary p { margin-top: 4px; font-weight: 400; }
        .mobile-change-group-count { display: inline-block; margin-inline-start: 8px; color: #6b7280; font-size: .75rem; }
        .mobile-change-points { list-style: disc; padding-inline-start: 22px; margin: 16px 0; font-size: .875rem; line-height: 1.9; }
        .mobile-change-files { display: grid; gap: 8px; margin-top: 12px; }
        .mobile-change-file { display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px; }
        .mobile-change-file code, .mobile-change-revision { direction: ltr; unicode-bidi: isolate; overflow-wrap: anywhere; font-size: .75rem; }
        .mobile-change-file-status { font-size: .75rem; color: #6b7280; }
        .mobile-change-history { display: grid; gap: 16px; margin: 16px 0; }
        .mobile-change-history li { border-inline-start: 2px solid #d97706; padding-inline-start: 12px; line-height: 1.8; }
        .mobile-change-history time { display: block; font-size: .75rem; color: #6b7280; }
        .mobile-change-search { display: grid; gap: 8px; }
        .mobile-change-search input { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; background: transparent; color: inherit; }
        .mobile-change-search input:focus { outline: 2px solid #d97706; outline-offset: 2px; }
        .dark .mobile-change-stat { background: #1f2937; }
        .dark .mobile-change-group, .dark .mobile-change-search input { border-color: #374151; }
        .dark .mobile-change-stat span, .dark .mobile-change-note, .dark .mobile-change-file-status, .dark .mobile-change-group-count, .dark .mobile-change-history time { color: #9ca3af; }
        .dark .mobile-change-stat strong, .dark .mobile-change-links a { color: #fbbf24; }
        .dark .mobile-developer-tool { border-color: #374151; }
        .dark .mobile-developer-tool-description { color: #9ca3af; }
        .dark .mobile-developer-tool-icon, .dark .mobile-developer-tool-link { color: #fbbf24; }
        @media (min-width: 768px) { .mobile-developer-tools { grid-template-columns: repeat(2, minmax(0, 1fr)); } .mobile-change-stats { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
    </style>

    @php($inventory = $this->getChangeInventory())

    @if ($inventory)
        <x-filament::section>
            <x-slot name="heading">كل تغييرات Laravel منذ أول رفع</x-slot>
            <x-slot name="description">الواجهات الأربع أدناه جزء من الإضافات. هذا الجرد يشمل أيضًا خدمات الخادم والمزامنة والحسابات والصلاحيات وقاعدة البيانات والاختبارات وبيئة التشغيل.</x-slot>

            <div class="mobile-change-stats">
                <div class="mobile-change-stat"><strong>{{ $inventory['totals']['groups'] }}</strong><span>مجموعة تغييرات</span></div>
                <div class="mobile-change-stat"><strong>{{ $inventory['totals']['files'] }}</strong><span>ملفًا جديدًا أو معدّلًا</span></div>
                <div class="mobile-change-stat"><strong>{{ $inventory['totals']['migrations'] }}</strong><span>ترحيلًا لقاعدة البيانات</span></div>
                <div class="mobile-change-stat"><strong>{{ count($inventory['history']) }}</strong><span>محطة في سجل التطوير</span></div>
            </div>
            <p class="mobile-change-note">{{ $inventory['scope_note'] }}</p>
            <p class="mobile-change-note">{{ $inventory['baseline_note'] }}</p>
            <p class="mobile-change-note">
                مرجع البداية <code class="mobile-change-revision">{{ substr($inventory['baseline'], 0, 7) }}</code>
                وحتى المراجعة <code class="mobile-change-revision">{{ substr($inventory['revision'], 0, 7) }}</code>،
                بتاريخ {{ $inventory['audited_at'] }}.
                الملفات الجديدة: {{ $inventory['totals']['added'] }}، والمعدّلة: {{ $inventory['totals']['modified'] }}.
            </p>
            <nav class="mobile-change-links" aria-label="أقسام سجل التغييرات">
                <a href="#laravel-change-inventory">التفاصيل حسب الوظيفة</a>
                <a href="#laravel-change-history">التسلسل الزمني</a>
                <a href="#laravel-ancillary-changes">بقية ملفات المستودع</a>
                <a href="#laravel-local-changes">تنظيم المركز الحالي</a>
            </nav>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">واجهات الإدارة المضافة</x-slot>
        <x-slot name="description">هذه الأدوات لها صفحات إدارة فعلية. تظهر لك بحسب صلاحيات حسابك.</x-slot>

        <div class="mobile-developer-tools">
            @foreach ($this->getTools() as $tool)
                <a class="mobile-developer-tool" href="{{ $tool['url'] }}" wire:key="mobile-tool-{{ $tool['key'] }}">
                    <x-filament::icon :icon="$tool['icon']" class="mobile-developer-tool-icon" />
                    <div class="mobile-developer-tool-content">
                        <span class="mobile-developer-tool-title">{{ $tool['label'] }}</span>
                        @if ($tool['badge'])
                            <span class="mobile-developer-tool-badge" aria-label="عدد الطلبات المعلقة">{{ $tool['badge'] }}</span>
                        @endif
                        <p class="mobile-developer-tool-description">{{ $tool['description'] }}</p>
                        <span class="mobile-developer-tool-link">فتح الأداة</span>
                    </div>
                </a>
            @endforeach
        </div>
    </x-filament::section>

    @if ($inventory)
        <x-filament::section id="laravel-change-inventory">
            <x-slot name="heading">تفاصيل تغييرات Laravel</x-slot>
            <x-slot name="description">افتح المجموعة لعرض ما تغيّر وملفاته. يمكنك البحث باسم الوظيفة أو الملف لتحديد ما تريد مراجعته أو إصلاحه.</x-slot>

            <div x-data="{ query: '' }">
                <label class="mobile-change-search">
                    <span>البحث في التغييرات والملفات</span>
                    <input type="search" x-model.debounce.150ms="query" placeholder="مثل: المزامنة، الصلاحيات، PaymentService" />
                </label>
                <div class="mobile-change-list">
                    @foreach ($inventory['groups'] as $group)
                        @php($searchText = $group['title'].' '.$group['summary'].' '.implode(' ', $group['changes']).' '.implode(' ', array_column($group['files'], 'path')))
                        <details class="mobile-change-group" x-show="@js($searchText).toLocaleLowerCase().includes(query.trim().toLocaleLowerCase())">
                            <summary>
                                <strong>{{ $group['title'] }}</strong>
                                <span class="mobile-change-group-count">{{ count($group['files']) }} ملف</span>
                                <p class="mobile-change-note">{{ $group['summary'] }}</p>
                            </summary>
                            <ul class="mobile-change-points">
                                @foreach ($group['changes'] as $change)
                                    <li>{{ $change }}</li>
                                @endforeach
                            </ul>
                            <div class="mobile-change-files" aria-label="الملفات المتغيرة">
                                @foreach ($group['files'] as $file)
                                    <div class="mobile-change-file">
                                        <span class="mobile-change-file-status">{{ $file['status'] === 'A' ? 'جديد' : 'معدّل' }}</span>
                                        <code dir="ltr">{{ $file['path'] }}</code>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endforeach
                </div>
            </div>
            <p class="mobile-change-note" style="margin-top: 16px">{{ $inventory['unchanged_note'] }}</p>
        </x-filament::section>

        <x-filament::section id="laravel-change-history" collapsible collapsed>
            <x-slot name="heading">التسلسل الزمني وما أضيف ثم أزيل</x-slot>
            <x-slot name="description">{{ $inventory['history_note'] }}</x-slot>
            <ol class="mobile-change-history">
                @foreach ($inventory['history'] as $event)
                    <li>
                        <time datetime="{{ $event['date'] }}">{{ $event['date'] }}</time>
                        <span>{{ $event['title'] }}</span>
                        <code class="mobile-change-revision">{{ substr($event['commit'], 0, 7) }}</code>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>

        <x-filament::section id="laravel-ancillary-changes" collapsible collapsed>
            <x-slot name="heading">بقية ملفات المستودع خارج تطبيق الهاتف</x-slot>
            <x-slot name="description">{{ $inventory['ancillary_note'] }}</x-slot>
            <div class="mobile-change-list">
                @foreach ($inventory['ancillary_changes'] as $group)
                    <details class="mobile-change-group">
                        <summary>
                            <strong>{{ $group['title'] }}</strong>
                            <span class="mobile-change-group-count">{{ count($group['files']) }} ملف</span>
                            <p class="mobile-change-note">{{ $group['summary'] }}</p>
                        </summary>
                        <div class="mobile-change-files">
                            @foreach ($group['files'] as $file)
                                <div class="mobile-change-file">
                                    <span class="mobile-change-file-status">{{ $file['status'] === 'A' ? 'جديد' : 'معدّل' }}</span>
                                    <code dir="ltr">{{ $file['path'] }}</code>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section id="laravel-local-changes" collapsible collapsed>
            <x-slot name="heading">تنظيم المركز في النسخة المحلية</x-slot>
            <x-slot name="description">هذه تعديلات التجميع والجرد الحالية، وتُعرض منفصلة عن ملفات لقطة Git التاريخية أعلاه.</x-slot>
            <div class="mobile-change-files">
                @foreach ($inventory['local_changes'] as $change)
                    <div class="mobile-change-group">
                        <code class="mobile-change-revision" dir="ltr">{{ $change['path'] }}</code>
                        <p class="mobile-change-note">{{ $change['description'] }}</p>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if ($this->canManageSettings())
        <x-filament::section>
            <x-slot name="heading">رمز وضع المطور</x-slot>
            <x-slot name="description">إدارة الرمز الذي يتيح تفعيل وضع المطور داخل تطبيق الهاتف.</x-slot>

            <form wire:submit="submit">
                {{ $this->form }}
                <div class="mt-4">
                    <x-filament::button type="submit">حفظ الإعدادات</x-filament::button>
                </div>
            </form>
        </x-filament::section>
    @endif
</x-filament-panels::page>

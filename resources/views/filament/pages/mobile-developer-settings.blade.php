<x-filament-panels::page>
    @php($inventory = $this->getChangeInventory())

    @if ($inventory)
        <x-filament::section>
            <x-slot name="heading">كل تغييرات Laravel منذ أول رفع</x-slot>
            <x-slot name="description">الواجهات الأربع أدناه جزء من الإضافات. هذا الجرد يشمل أيضًا خدمات الخادم والمزامنة والحسابات والصلاحيات وقاعدة البيانات والاختبارات وبيئة التشغيل.</x-slot>

            <div class="flex flex-wrap gap-3">
                <x-filament::badge color="gray">مجموعات التغييرات: {{ $inventory['totals']['groups'] }}</x-filament::badge>
                <x-filament::badge color="gray">الملفات: {{ $inventory['totals']['files'] }}</x-filament::badge>
                <x-filament::badge color="gray">ترحيلات قاعدة البيانات: {{ $inventory['totals']['migrations'] }}</x-filament::badge>
                <x-filament::badge color="gray">محطات التطوير: {{ count($inventory['history']) }}</x-filament::badge>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $inventory['scope_note'] }}</p>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $inventory['baseline_note'] }}</p>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                مرجع البداية <code class="text-xs break-all">{{ substr($inventory['baseline'], 0, 7) }}</code>
                وحتى المراجعة <code class="text-xs break-all">{{ substr($inventory['revision'], 0, 7) }}</code>،
                بتاريخ {{ $inventory['audited_at'] }}.
                الملفات الجديدة: {{ $inventory['totals']['added'] }}، والمعدّلة: {{ $inventory['totals']['modified'] }}.
            </p>
            <nav class="flex flex-wrap gap-3" aria-label="أقسام سجل التغييرات">
                <x-filament::button tag="a" color="gray" href="#laravel-change-inventory">التفاصيل حسب الوظيفة</x-filament::button>
                <x-filament::button tag="a" color="gray" href="#laravel-change-history">التسلسل الزمني</x-filament::button>
                <x-filament::button tag="a" color="gray" href="#laravel-ancillary-changes">بقية ملفات المستودع</x-filament::button>
                <x-filament::button tag="a" color="gray" href="#laravel-local-changes">تنظيم المركز الحالي</x-filament::button>
            </nav>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">واجهات الإدارة المضافة</x-slot>
        <x-slot name="description">هذه الأدوات لها صفحات إدارة فعلية. تظهر لك بحسب صلاحيات حسابك.</x-slot>

        <div class="grid gap-4">
            @foreach ($this->getTools() as $tool)
                <x-filament::section :icon="$tool['icon']" wire:key="mobile-tool-{{ $tool['key'] }}">
                    <x-slot name="heading">{{ $tool['label'] }}</x-slot>
                    <x-slot name="description">{{ $tool['description'] }}</x-slot>
                    @if ($tool['badge'])
                        <x-filament::badge color="warning" aria-label="عدد الطلبات المعلقة">{{ $tool['badge'] }}</x-filament::badge>
                    @endif
                    <x-filament::button tag="a" :href="$tool['url']" color="gray" icon="heroicon-o-arrow-top-right-on-square">فتح الأداة</x-filament::button>
                </x-filament::section>
            @endforeach
        </div>
    </x-filament::section>

    @if ($inventory)
        <x-filament::section id="laravel-change-inventory">
            <x-slot name="heading">تفاصيل تغييرات Laravel</x-slot>
            <x-slot name="description">افتح المجموعة لعرض ما تغيّر وملفاته. يمكنك البحث باسم الوظيفة أو الملف لتحديد ما تريد مراجعته أو إصلاحه.</x-slot>

            <div x-data="{ query: '' }">
                <label for="mobile-change-search" class="text-sm font-medium">البحث في التغييرات والملفات</label>
                <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                    <x-filament::input id="mobile-change-search" type="search" x-model.debounce.150ms="query" placeholder="مثل: المزامنة، الصلاحيات، PaymentService" />
                </x-filament::input.wrapper>
                <div class="grid gap-3">
                    @foreach ($inventory['groups'] as $group)
                        @php($searchText = $group['title'].' '.$group['summary'].' '.implode(' ', $group['changes']).' '.implode(' ', array_column($group['files'], 'path')))
                        <div x-show="@js($searchText).toLocaleLowerCase().includes(query.trim().toLocaleLowerCase())">
                            <x-filament::section collapsible collapsed>
                                <x-slot name="heading">{{ $group['title'] }} ({{ count($group['files']) }} ملف)</x-slot>
                                <x-slot name="description">{{ $group['summary'] }}</x-slot>
                            <ul class="list-disc ps-5 text-sm space-y-1">
                                @foreach ($group['changes'] as $change)
                                    <li>{{ $change }}</li>
                                @endforeach
                            </ul>
                            <div class="grid gap-2" aria-label="الملفات المتغيرة">
                                @foreach ($group['files'] as $file)
                                    <div class="flex flex-wrap items-center gap-2 text-xs break-all">
                                        <span class="text-gray-600 dark:text-gray-400">{{ $file['status'] === 'A' ? 'جديد' : 'معدّل' }}</span>
                                        <code dir="ltr">{{ $file['path'] }}</code>
                                    </div>
                                @endforeach
                            </div>
                        </x-filament::section></div>
                    @endforeach
                </div>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $inventory['unchanged_note'] }}</p>
        </x-filament::section>

        <x-filament::section id="laravel-change-history" collapsible collapsed>
            <x-slot name="heading">التسلسل الزمني وما أضيف ثم أزيل</x-slot>
            <x-slot name="description">{{ $inventory['history_note'] }}</x-slot>
            <ol class="grid gap-3 text-sm">
                @foreach ($inventory['history'] as $event)
                    <li>
                        <time datetime="{{ $event['date'] }}">{{ $event['date'] }}</time>
                        <span>{{ $event['title'] }}</span>
                        <code class="text-xs break-all">{{ substr($event['commit'], 0, 7) }}</code>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>

        <x-filament::section id="laravel-ancillary-changes" collapsible collapsed>
            <x-slot name="heading">بقية ملفات المستودع خارج تطبيق الهاتف</x-slot>
            <x-slot name="description">{{ $inventory['ancillary_note'] }}</x-slot>
            <div class="grid gap-3">
                @foreach ($inventory['ancillary_changes'] as $group)
                    <div>
                            <x-filament::section collapsible collapsed>
                                <x-slot name="heading">{{ $group['title'] }} ({{ count($group['files']) }} ملف)</x-slot>
                                <x-slot name="description">{{ $group['summary'] }}</x-slot>
                        <div class="grid gap-2">
                            @foreach ($group['files'] as $file)
                                <div class="flex flex-wrap items-center gap-2 text-xs break-all">
                                    <span class="text-gray-600 dark:text-gray-400">{{ $file['status'] === 'A' ? 'جديد' : 'معدّل' }}</span>
                                    <code dir="ltr">{{ $file['path'] }}</code>
                                </div>
                            @endforeach
                        </div>
                    </x-filament::section></div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section id="laravel-local-changes" collapsible collapsed>
            <x-slot name="heading">تنظيم المركز في النسخة المحلية</x-slot>
            <x-slot name="description">هذه تعديلات التجميع والجرد الحالية، وتُعرض منفصلة عن ملفات لقطة Git التاريخية أعلاه.</x-slot>
            <div class="grid gap-2">
                @foreach ($inventory['local_changes'] as $change)
                    <div class="grid gap-2">
                        <code class="text-xs break-all" dir="ltr">{{ $change['path'] }}</code>
                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $change['description'] }}</p>
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

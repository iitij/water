<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $this->record->name }}</x-slot>
        <x-slot name="description">كشف الحساب الموحد حسب تاريخ الترحيل المحاسبي. الموجب دائن والسالب مدين.</x-slot>
        <form wire:submit="loadStatement" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="ledger-from">من تاريخ</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="ledger-from" type="date" wire:model.live="fromDate" />
                    </x-filament::input.wrapper>
                    @error('fromDate') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="ledger-to">إلى تاريخ</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="ledger-to" type="date" wire:model.live="toDate" />
                    </x-filament::input.wrapper>
                    @error('toDate') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>
            </div>
            @error('ledger') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
            <x-filament::button type="submit" wire:loading.attr="disabled">عرض الكشف</x-filament::button>
        </form>
    </x-filament::section>

    @if ($statement !== null)
        @php
            $money = fn ($minor) => number_format($minor / 100, 2, '.', ',');
            $period = ['subscriber' => $statement['subscriber']['id'], 'from_date' => $statement['from_date'], 'to_date' => $statement['to_date']];
        @endphp
        <x-filament::section>
            <x-slot name="heading">الأرصدة خلال الفترة</x-slot>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach (['opening_balance_minor' => 'رصيد الافتتاح', 'debit_minor' => 'الحركات المدينة', 'credit_minor' => 'الحركات الدائنة', 'closing_balance_minor' => 'رصيد الإغلاق'] as $key => $label)
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-sm">{{ $label }}</p>
                        <p class="mt-2 text-xl font-semibold tabular-nums" dir="ltr">{{ $money($statement[$key]) }}</p>
                    </div>
                @endforeach
            </div>
            <p class="mt-4 text-sm">الفترة: {{ $statement['from_date'] }} — {{ $statement['to_date'] }}. جميع المبالغ بالريال.</p>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-filament::button tag="a" :href="route('subscribers.ledger-statement.download', $period + ['format' => 'pdf'])" target="_blank" rel="noopener" icon="heroicon-o-document-text">PDF</x-filament::button>
                @can('View:ExportDataPage')
                    <x-filament::button tag="a" color="gray" :href="route('subscribers.ledger-statement.download', $period + ['format' => 'xlsx'])" icon="heroicon-o-arrow-down-tray">Excel</x-filament::button>
                @endcan
            </div>
            <p class="mt-2 text-sm">يُنشأ الملف من أحدث القيود المسجلة للفترة عند التنزيل.</p>
        </x-filament::section>
        <x-filament::section>
            <x-slot name="heading">الحركات المرحّلة ({{ $statement['entry_count'] }})</x-slot>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr>
                        @foreach (['رقم القيد','تاريخ الترحيل','البيان','مرجع العداد','مدين','دائن','الرصيد المتحرك'] as $label)
                            <th class="p-3 text-start">{{ $label }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @forelse ($statement['entries'] as $entry)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="p-3">{{ $entry['id'] }}</td><td class="p-3 whitespace-nowrap" dir="ltr">{{ $entry['posting_date'] }}</td>
                                <td class="p-3">{{ $entry['description'] ?? $entry['type'] }}</td><td class="p-3">{{ $entry['meter_code'] ?? '—' }}</td>
                                @foreach (['debit_minor','credit_minor','running_balance_minor'] as $key)
                                    <td class="p-3 tabular-nums" dir="ltr">{{ $money($entry[$key]) }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="7" class="p-4">لا توجد حركات خلال الفترة.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>

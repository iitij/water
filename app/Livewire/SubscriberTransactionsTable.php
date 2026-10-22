<?php

namespace App\Livewire;

use App\Models\Transaction;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;

use Filament\Actions\Contracts\HasActions;
use Filament\Actions\Concerns\InteractsWithActions;

class SubscriberTransactionsTable extends Component implements HasForms, HasTable, HasActions
{
    use InteractsWithForms;
    use InteractsWithTable;
    use InteractsWithActions;

    public ?\App\Models\Subscriber $record = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading('سجل الحركات')
            ->query(
                Transaction::query()->where('subscriber_id', $this->record?->id ?? -1)->latest()
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'credit' => 'دائن',
                        'debit' => 'مدين',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'credit' => 'success',
                        'debit' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('meter_id')
                    ->label('الجهة')
                    ->formatStateUsing(function ($state, $record) {
                        return $state ? 'عداد رقم: ' . $state : 'الحساب العام';
                    })
                    ->badge()
                    ->color(fn ($state) => $state ? 'info' : 'gray'),
                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->numeric(2)
                    ->suffix(' ريال')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('البيان'),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('meter_id')
                    ->label('الحساب (الجهة)')
                    ->options(function () {
                        if (!$this->record) return [];
                        $options = ['general' => 'الحساب العام'];
                        foreach ($this->record->meters as $meter) {
                            $options[$meter->id] = 'عداد رقم: ' . $meter->id . ($meter->tenant_name ? ' - ' . $meter->tenant_name : '');
                        }
                        return $options;
                    })
                    ->query(function ($query, array $data) {
                        if (isset($data['value']) && $data['value'] === 'general') {
                            $query->whereNull('meter_id');
                        } elseif (!empty($data['value'])) {
                            $query->where('meter_id', $data['value']);
                        }
                    }),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }

    public function render()
    {
        return view('livewire.subscriber-transactions-table');
    }
}

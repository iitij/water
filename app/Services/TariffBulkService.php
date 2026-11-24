<?php

namespace App\Services;

use App\Models\Tariff;
use Illuminate\Support\Facades\DB;

final class TariffBulkService
{
    public function apply(array $ids, string $operation): int
    {
        if (! in_array($operation, ['delete', 'forceDelete', 'restore'], true)) {
            throw new \InvalidArgumentException('Unknown tariff operation.');
        }

        return DB::transaction(function () use ($ids, $operation): int {
            Tariff::lockCatalog();
            $query = match ($operation) {
                'restore' => Tariff::onlyTrashed(),
                'forceDelete' => Tariff::withTrashed(),
                default => Tariff::query(),
            };
            $records = $query->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $count = 0;
            foreach ($records as $record) {
                if (! $record->{$operation}()) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'tariff' => 'تعذر إكمال العملية الجماعية؛ لم تُعتمد أي تغييرات.',
                    ]);
                }
                $count++;
            }

            return $count;
        });
    }
}

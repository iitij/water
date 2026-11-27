<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Serialize non-posting observations with the financial close and posting writers.
 * Recording physical evidence does not itself authorize any financial posting.
 */
final class ReadingObservationFence
{
    public function lock(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Observation fence requires an outer transaction.');
        }
        $scope = DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first();
        if ($scope && ($scope->active_freeze_uuid !== null || $scope->mode !== 'ACTIVE')) {
            throw ValidationException::withMessages(['reading_date' => 'لا يمكن تغيير القراءات أثناء تجميد النطاق المالي.']);
        }
        if (! $scope && config('financial_candidate.invoices') === true) {
            throw ValidationException::withMessages(['reading_date' => 'النطاق المالي غير مهيأ.']);
        }
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Models\Meter;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\Square;
use App\Services\ReadingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SquareController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorizeApi(Square::class, 'viewAny');

        $query = Square::query()->withCount('subscribers')->orderBy('name');
        if ($search = trim((string) $request->query('search', $request->query('q', '')))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $this->applySorting($query, $request, self::LIST_SORTS['squares']);
        if ($request->filled('per_page')) {
            return $query->paginate($this->perPage($request))->appends($request->query());
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $this->authorizeApi(Square::class, 'create');
        if (is_string($request->input('code'))) {
            $code = trim($request->input('code'));
            $request->merge(['code' => $code === '' ? null : $code]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:100', Rule::unique('squares', 'code')],
            'description' => ['nullable', 'string'],
        ], ['code.string' => 'أدخل معرّف المربع كنص.', 'code.unique' => 'معرّف المربع مستخدم مسبقاً. اختر معرّفاً آخر.', 'code.max' => 'معرّف المربع يجب ألا يتجاوز 100 حرف.']);

        $square = Square::create($data)->loadCount('subscribers');

        return response()->json(['message' => 'تم إنشاء المربع بنجاح.', 'square' => $square], 201);
    }

    public function show(int $id)
    {
        $this->authorizeApi(Square::class, 'view');
        $square = Square::withCount('subscribers')->with(['subscribers' => function ($query) {
            $query->select('id', 'square_id', 'name', 'phone', 'address', 'status', 'balance')
                ->with('meters:id,subscriber_id,unique_code,path_number,balance,status');
        }])->findOrFail($id);

        $square->setRelation('subscribers', $square->subscribers->map(function ($subscriber) {
            $credit = max(0, (float) $subscriber->balance);
            $debit = $subscriber->getOutstandingBalance();
            foreach ($subscriber->meters as $meter) {
                $credit += max(0, (float) $meter->balance);
                $debit += $meter->getOutstandingBalance();
            }

            $subscriber->setAttribute('credit_balance', round($credit, 2));
            $subscriber->setAttribute('debit_balance', round($debit, 2));
            $subscriber->setAttribute('account_balance', round($credit - $debit, 2));

            return $subscriber;
        }));

        // Return the square at the top level for the SCR-04 mobile model.
        return response()->json($square);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeApi(Square::class, 'update');
        $square = Square::findOrFail($id);
        if (is_string($request->input('code'))) {
            $code = trim($request->input('code'));
            $request->merge(['code' => $code === '' ? null : $code]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:100', Rule::unique('squares', 'code')->ignore($square->id)],
            'description' => ['nullable', 'string'],
        ], ['code.string' => 'أدخل معرّف المربع كنص.', 'code.unique' => 'معرّف المربع مستخدم مسبقاً. اختر معرّفاً آخر.', 'code.max' => 'معرّف المربع يجب ألا يتجاوز 100 حرف.']);
        $square->update($data);

        return response()->json(['message' => 'تم تحديث المربع بنجاح.', 'square' => $square->loadCount('subscribers')]);
    }

    public function destroy(int $id)
    {
        $this->authorizeApi(Square::class, 'delete');
        $square = Square::findOrFail($id);
        if ($square->subscribers()->exists()) {
            return response()->json(['message' => 'لا يمكن حذف مربع يحتوي على مشتركين. انقل المشتركين أولاً.'], 422);
        }
        $square->delete();

        return response()->json(['message' => 'تم حذف المربع بنجاح.']);
    }

    public function bulkDelete(Request $request)
    {
        $this->authorizeApi(Square::class, 'deleteAny');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:squares,id']]);
        $deleted = 0;
        $skipped = [];

        DB::transaction(function () use ($data, &$deleted, &$skipped) {
            foreach (Square::whereIn('id', $data['ids'])->lockForUpdate()->get() as $square) {
                if ($square->subscribers()->exists()) {
                    $skipped[] = $square->id;

                    continue;
                }
                $square->delete();
                $deleted++;
            }
        });

        return response()->json(['deleted_count' => $deleted, 'skipped_count' => count($skipped), 'skipped_ids' => $skipped]);
    }

    public function offlinePack(int $id, ReadingService $readingService)
    {
        $this->authorizeApi(Square::class, 'view');
        $square = Square::withCount('subscribers')->findOrFail($id);
        $subscribers = $square->subscribers()
            ->select('id', 'square_id', 'name', 'phone', 'address', 'status', 'balance', 'subscription_start_date')
            ->orderBy('name')
            ->get()
            ->each(function (\App\Models\Subscriber $subscriber): void {
                $subscriber->setAttribute('account_balance', $subscriber->getAccountBalance());
            });

        $meters = Meter::with(['subscriber:id,name,phone,square_id', 'subscriber.square:id,name,code'])
            ->withCount('readings')
            ->whereHas('subscriber', fn ($q) => $q->where('square_id', $square->id))
            ->orderBy('path_number')
            ->orderBy('id')
            ->get()
            ->map(function (Meter $meter) use ($readingService) {
                $lastReading = $meter->readings()->latest('id')->first();
                $period = $readingService->calculateReadingPeriod($meter->id);

                $meter->setAttribute('last_reading', $lastReading ? (float) $lastReading->current_reading : (float) $meter->initial_reading);
                $meter->setAttribute('period', [
                    'period_start' => $period['period_start'],
                    'period_end' => $period['period_end'],
                    'auto_issue_invoice' => filter_var(Setting::get('auto_issue_invoice_on_reading', '0'), FILTER_VALIDATE_BOOLEAN),
                ]);

                return $meter;
            });

        $readings = Reading::with(['meter:id,unique_code,path_number,subscriber_id', 'invoice:id,bill_number,status'])
            ->whereHas('meter.subscriber', fn ($q) => $q->where('square_id', $square->id))
            ->latest('reading_date')
            ->limit(250)
            ->get();

        return response()->json([
            'square' => $square,
            'subscribers' => $subscribers,
            'meters' => $meters,
            'readings' => $readings,
            'reading_settings' => [
                'reading_period' => Setting::get('reading_period', 'monthly'),
                'reading_day' => Setting::get('reading_day', 'last_day'),
                'auto_issue_invoice_on_reading' => filter_var(Setting::get('auto_issue_invoice_on_reading', '0'), FILTER_VALIDATE_BOOLEAN),
                'grace_period' => (int) Setting::get('grace_period', '14'),
                'strict_overdue_on_new_invoice' => filter_var(Setting::get('strict_overdue_on_new_invoice', '0'), FILTER_VALIDATE_BOOLEAN),
            ],
            'generated_at' => now()->toISOString(),
        ]);
    }
}

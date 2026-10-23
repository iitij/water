<?php

namespace App\Http\Controllers\Api;

use App\Models\Square;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SquareController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorizeApi(Square::class, 'viewAny');

        $query = Square::query()->withCount('subscribers')->orderBy('name');
        if ($search = trim((string) $request->query('search', $request->query('q', '')))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('per_page')) {
            return $query->paginate($this->perPage($request))->appends($request->query());
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $this->authorizeApi(Square::class, 'create');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);

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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);
        $square->update($data);

        return response()->json(['message' => 'تم تحديث المربع بنجاح.', 'square' => $square->loadCount('subscribers')]);
    }

    public function destroy(int $id)
    {
        $this->authorizeApi(Square::class, 'delete');
        $square = Square::findOrFail($id);
        if ($square->subscribers()->exists()) {
            return response()->json(['message' => 'لا يمكن حذف مربع يحتوي على مشتركين. انقل المشتركين أولاً.'], 409);
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
}

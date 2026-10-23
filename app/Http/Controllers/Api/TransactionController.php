<?php

namespace App\Http\Controllers\Api;

use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorizeApi(Transaction::class, 'viewAny');
        $query = Transaction::with([
            'subscriber:id,name,phone,square_id', 'subscriber.square:id,name',
            'meter:id,unique_code,path_number,tenant_name', 'reference',
        ])->latest('created_at')->latest('id');
        foreach (['subscriber_id', 'type'] as $filter) {
            if ($request->filled($filter)) $query->where($filter, $request->query($filter));
        }
        if ($request->filled('meter_id')) {
            $meterId = (string) $request->query('meter_id');
            $meterId === 'general'
                ? $query->whereNull('meter_id')
                : $query->where('meter_id', (int) $meterId);
        }
        if ($request->filled('square_id')) {
            $query->whereHas('subscriber', fn ($q) => $q->where('square_id', $request->integer('square_id')));
        }
        if ($request->filled('from_date')) $query->whereDate('created_at', '>=', $request->query('from_date'));
        if ($request->filled('to_date')) $query->whereDate('created_at', '<=', $request->query('to_date'));
        if ($search = trim((string) $request->query('q', $request->query('search', '')))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('description', 'like', "%{$search}%")
                    ->orWhereHas('subscriber', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('meter', fn ($q) => $q->where('unique_code', 'like', "%{$search}%"));
            });
        }
        return $query->paginate($this->perPage($request))->appends($request->query());
    }

    public function show(int $id)
    {
        $this->authorizeApi(Transaction::class, 'view');
        return response()->json(['transaction' => Transaction::with(['subscriber.square', 'meter', 'reference'])->findOrFail($id)]);
    }
}

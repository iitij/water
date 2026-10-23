<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;

/**
 * دفتر الأستاذ العام — SCR-22 (Endpoint 59). للقراءة فقط (Read-Only Ledger).
 * الأنواع الفعلية في قاعدة البيانات: payment (دائن) / invoice (مدين) / adjustment (تسوية)،
 * مع قبول مرادفات الخطة credit/debit وتحويلها.
 */
class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $type = match ($request->type) {
            'credit' => 'payment',
            'debit' => 'invoice',
            default => $request->type,
        };

        $query = Transaction::with(['subscriber:id,name', 'meter:id,unique_code,tenant_name'])
            ->when($request->filled('search'), fn ($q) => $q->where(function ($sub) use ($request) {
                $sub->where('description', 'like', '%' . $request->search . '%')
                    ->orWhereHas('subscriber', fn ($s) => $s->where('name', 'like', '%' . $request->search . '%'));
            }))
            ->when($request->filled('subscriber_id'), fn ($q) => $q->where('subscriber_id', $request->subscriber_id))
            ->when($request->filled('meter_id'), function ($q) use ($request) {
                $request->meter_id === 'general'
                    ? $q->whereNull('meter_id')
                    : $q->where('meter_id', $request->meter_id);
            })
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('created_at', '>=', $request->from_date))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('created_at', '<=', $request->to_date))
            ->latest();

        return response()->json(
            $request->filled('per_page') ? $query->paginate((int) $request->per_page) : $query->get()
        );
    }
}

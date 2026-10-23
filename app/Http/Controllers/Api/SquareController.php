<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Square;
use Illuminate\Http\Request;

/**
 * المربعات السكنية — SCR-04 (Endpoints 6-11 في الخطة الرئيسية 2.3).
 */
class SquareController extends Controller
{
    public function index(Request $request)
    {
        $squares = Square::withCount('subscribers')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%' . $request->search . '%'))
            ->orderBy('id')
            ->get(['id', 'name', 'description', 'created_at']);

        return response()->json($squares);
    }

    public function show(int $id)
    {
        $square = Square::with(['subscribers' => fn ($q) => $q->orderBy('name')
            ->select('id', 'name', 'phone', 'address', 'status', 'balance', 'square_id')])
            ->withCount('subscribers')
            ->find($id);

        if (! $square) {
            return response()->json(['message' => 'المربع غير موجود'], 404);
        }

        return response()->json($square);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
        ]);

        $square = Square::create($validated)->loadCount('subscribers');

        return response()->json(['message' => 'تم إضافة المربع بنجاح', 'square' => $square], 201);
    }

    public function update(Request $request, int $id)
    {
        $square = Square::find($id);
        if (! $square) {
            return response()->json(['message' => 'المربع غير موجود'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
        ]);

        $square->update($validated);

        return response()->json(['message' => 'تم تحديث المربع بنجاح', 'square' => $square->loadCount('subscribers')]);
    }

    public function destroy(int $id)
    {
        $square = Square::find($id);
        if (! $square) {
            return response()->json(['message' => 'المربع غير موجود'], 404);
        }
        if ($square->subscribers()->exists()) {
            return response()->json(['message' => 'لا يمكن حذف مربع يحتوي على مشتركين'], 422);
        }

        $square->delete();

        return response()->json(['message' => 'تم حذف المربع بنجاح']);
    }

    public function bulkDelete(Request $request)
    {
        $validated = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        $deleted = Square::whereIn('id', $validated['ids'])
            ->whereDoesntHave('subscribers')->delete();

        return response()->json([
            'message' => 'تم حذف المربعات بنجاح',
            'deleted_count' => $deleted,
            'skipped_count' => count($validated['ids']) - $deleted,
        ]);
    }
}

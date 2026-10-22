<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Reading;
use App\Models\Meter;
use App\Services\ReadingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use App\Models\Setting;
use App\Services\InvoiceService;

class ReadingController extends Controller
{
    protected ReadingService $readingService;

    public function __construct(ReadingService $readingService)
    {
        $this->readingService = $readingService;
    }

    public function index()
    {
        // For testing/reader purposes, fetch recent readings
        $readings = Reading::with(['meter.subscriber'])->orderBy('id', 'desc')->limit(50)->get();
        return response()->json($readings);
    }

    public function searchMeter(Request $request)
    {
        $request->validate([
            'query' => 'nullable|string',
            'square_id' => 'nullable|integer',
        ]);
        
        $query = $request->input('query');
        $squareId = $request->input('square_id');
        
        $metersQuery = Meter::with('subscriber.square');

        if ($query) {
            // Check for compound search: Square/Path (e.g. 4/1, 4-1, 4_1, 4\1)
            if (preg_match('/^(\d+)[\/\-\\\\_](\d+)$/', $query, $matches)) {
                $sqId = $matches[1];
                $pathNo = $matches[2];
                $metersQuery->whereHas('subscriber', function($q) use ($sqId) {
                    $q->where('square_id', $sqId);
                })->where('path_number', $pathNo);
            } else {
                $metersQuery->where(function($q) use ($query) {
                    $q->where('unique_code', 'LIKE', "%$query%")
                      ->orWhere('path_number', $query)
                      ->orWhereHas('subscriber', function($subQ) use ($query) {
                          $subQ->where('name', 'LIKE', "%$query%");
                      });
                });
            }
        }

        if ($squareId) {
            $metersQuery->whereHas('subscriber', function($q) use ($squareId) {
                $q->where('square_id', $squareId);
            });
        }
        
        $meters = $metersQuery->get();
        
        // Add flag and sort
        if ($query) {
            if (preg_match('/^(\d+)[\/\-\\\\_](\d+)$/', $query)) {
                $meters->each(function($meter) {
                    $meter->is_path_match = true;
                });
            } else {
                $meters->each(function($meter) use ($query) {
                    $meter->is_path_match = (string)$meter->path_number === (string)$query;
                });
                $meters = $meters->sortByDesc('is_path_match')->values();
            }
        }
            
        return response()->json($meters);
    }

    public function getMeterPeriod(int $meterId)
    {
        $meter = Meter::with('subscriber')->find($meterId);
        if (!$meter) {
            return response()->json(['message' => 'العداد غير موجود'], 404);
        }

        $lastReading = Reading::where('meter_id', $meterId)->orderBy('id', 'desc')->first();
        $period = $this->readingService->calculateReadingPeriod($meterId);

        $hasSub = false;
        $subAmount = 0;
        $hasTanker = false;
        $tankerAmount = 0;

        if ($meter->subscriber) {
            $subAmount = (float) \App\Models\SubscriptionFee::where('subscriber_id', $meter->subscriber->id)
                ->where('status', 'uninvoiced')
                ->sum('amount');
            $hasSub = $subAmount > 0 || \App\Models\SubscriptionFee::where('subscriber_id', $meter->subscriber->id)->where('status', 'uninvoiced')->exists();

            $tankerAmount = (float) \App\Models\ServiceRequest::where('subscriber_id', $meter->subscriber->id)
                ->where('status', 'completed')
                ->sum('amount');
            $hasTanker = $tankerAmount > 0 || \App\Models\ServiceRequest::where('subscriber_id', $meter->subscriber->id)->where('status', 'completed')->exists();
        }

        return response()->json([
            'meter' => $meter,
            'last_reading' => $lastReading ? $lastReading->current_reading : 0,
            'period_start' => $period['period_start'],
            'period_end' => $period['period_end'],
            'auto_issue_invoice' => Setting::get('auto_issue_invoice_on_reading', '0') == '1',
            'has_subscription_fee' => $hasSub,
            'subscription_installment' => $subAmount,
            'has_tanker_fee' => $hasTanker,
            'tanker_installment' => $tankerAmount,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'meter_id' => 'required|exists:meters,id',
            'reading_date' => 'required|date',
            'current_reading' => 'required|numeric|min:0',
            'image' => 'nullable|image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $meterId = $request->meter_id;
        $currentReading = $request->current_reading;

        // Validation from backend: Previous reading must be less than or equal to current
        $lastReadingModel = Reading::where('meter_id', $meterId)->orderBy('id', 'desc')->first();
        $previousReading = $lastReadingModel ? $lastReadingModel->current_reading : 0;

        if ($currentReading < $previousReading) {
            return response()->json([
                'errors' => [
                    'current_reading' => ['القراءة الحالية يجب أن تكون أكبر من أو تساوي القراءة السابقة (' . $previousReading . ')']
                ]
            ], 422);
        }

        // Get calculated period
        $period = $this->readingService->calculateReadingPeriod($meterId);
        if (!$period['period_start'] || !$period['period_end']) {
            return response()->json(['errors' => ['general' => ['لا يمكن حساب فترة القراءة.']]], 422);
        }

        // Prevent exact period duplication
        $exists = Reading::where('meter_id', $meterId)
            ->where('period_start', $period['period_start'])
            ->where('period_end', $period['period_end'])
            ->exists();

        if ($exists) {
             return response()->json([
                'errors' => [
                    'general' => ['تم إدخال قراءة سابقة لنفس هذه الفترة (' . $period['period_start'] . ' إلى ' . $period['period_end'] . ').']
                ]
            ], 422);
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('readings', 'public');
        }

        $consumption = $currentReading - $previousReading;

        $reading = new Reading();
        $reading->meter_id = $meterId;
        $reading->created_by = $request->user()->id;
        $reading->reading_date = $request->reading_date;
        $reading->previous_reading = $previousReading;
        $reading->current_reading = $currentReading;
        $reading->period_start = $period['period_start'];
        $reading->period_end = $period['period_end'];
        $reading->save();

        $invoiceIssued = false;
        $invoiceData = null;
        if (Setting::get('auto_issue_invoice_on_reading', '0') == '1') {
            try {
                $invoiceService = new InvoiceService();
                $meter = Meter::with(['subscriber.square'])->find($meterId);
                if ($meter && $meter->subscriber) {
                    $subscriptionInstallment = ($request->has('has_subscription_fee') && $request->has_subscription_fee && $request->has_subscription_fee !== 'false' && $request->has_subscription_fee !== '0') ? (float) ($request->subscription_installment ?? 0) : null;
                    $tankerInstallment = ($request->has('has_tanker_fee') && $request->has_tanker_fee && $request->has_tanker_fee !== 'false' && $request->has_tanker_fee !== '0') ? (float) ($request->tanker_installment ?? 0) : null;

                    $invoice = $invoiceService->generateInvoice(
                        subscriber: $meter->subscriber,
                        meter: $meter,
                        subscriptionInstallment: $subscriptionInstallment,
                        notes: null,
                        tankerInstallment: $tankerInstallment
                    );
                    $invoiceIssued = true;
                    
                    $unitPrice = $reading->consumption_units > 0 && $invoice->consumption_fee > 0 
                        ? round($invoice->consumption_fee / $reading->consumption_units, 2) 
                        : \App\Models\Tariff::getActivePrice();

                    $invoiceData = [
                        'id' => $invoice->id,
                        'bill_number' => $invoice->bill_number,
                        'bill_date' => $invoice->bill_date ? $invoice->bill_date->format('Y-m-d') : date('Y-m-d'),
                        'total_amount' => number_format((float)(($invoice->total_amount ?? 0) + ($invoice->previous_balance ?? 0) - ($invoice->credit_applied ?? 0)), 2, '.', ''),
                        'subscriber_name' => $meter->subscriber->name ?? '',
                        'subscriber_phone' => $meter->subscriber->phone ?? '',
                        'meter_code' => $meter->unique_code ?? '',
                        'square' => $meter->subscriber->square->name ?? '',
                        'address' => $meter->subscriber->address ?? '-',
                        'pdf_url' => url('/invoices/' . $invoice->id . '/print'),
                        'period_start' => $reading->period_start,
                        'period_end' => $reading->period_end,
                        'previous_reading' => $reading->previous_reading,
                        'current_reading' => $reading->current_reading,
                        'consumption_units' => $reading->consumption_units,
                        'unit_price' => number_format((float)$unitPrice, 2, '.', ''),
                        'consumption_fee' => number_format((float)($invoice->consumption_fee ?? 0), 2, '.', ''),
                        'subscription_installment' => number_format((float)($invoice->subscription_installment ?? 0), 2, '.', ''),
                        'tanker_charges' => number_format((float)($invoice->tanker_charges ?? 0), 2, '.', ''),
                        'previous_balance' => number_format((float)($invoice->previous_balance ?? 0), 2, '.', ''),
                        'credit_applied' => number_format((float)($invoice->credit_applied ?? 0), 2, '.', ''),
                        'collector' => $request->user()->name ?? '',
                        'message_template' => Setting::get('reading_invoice_message_template', null),
                    ];
                }
            } catch (\Exception $e) {
                // Ignore or log error
            }
        }

        return response()->json([
            'message' => 'تم حفظ القراءة بنجاح',
            'reading' => $reading,
            'invoice_issued' => $invoiceIssued,
            'invoice_data' => $invoiceData,
        ], 201);
    }
}

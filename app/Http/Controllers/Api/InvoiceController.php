<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    public function unpaidInvoices()
    {
        // Fetch invoices that are unpaid or partially paid
        $invoices = Invoice::with(['subscriber', 'meter'])
            ->whereIn('status', ['issued', 'overdue', 'partially_paid'])
            ->orderBy('id', 'desc')
            ->get();
            
        $invoices->each(function ($invoice) {
            $invoice->true_remaining_amount = $invoice->getTrueRemainingAmount();
        });
            
        return response()->json($invoices);
    }

    public function search(Request $request)
    {
        $request->validate([
            'query' => 'nullable|string',
            'square_id' => 'nullable|integer',
        ]);
        
        $query = $request->input('query');
        $squareId = $request->input('square_id');
        
        $invoicesQuery = Invoice::with(['subscriber.square', 'meter'])
            ->whereIn('status', ['issued', 'overdue', 'partially_paid']);

        if ($query) {
            // Check for compound search: Square/Path (e.g. 4/1, 4-1, 4_1, 4\1)
            if (preg_match('/^(\d+)[\/\-\\\\_](\d+)$/', $query, $matches)) {
                $sqId = $matches[1];
                $pathNo = $matches[2];
                $invoicesQuery->whereHas('subscriber', function($q) use ($sqId) {
                    $q->where('square_id', $sqId);
                })->whereHas('meter', function($q) use ($pathNo) {
                    $q->where('path_number', $pathNo);
                });
            } else {
                $invoicesQuery->where(function($q) use ($query) {
                    $q->where('bill_number', 'LIKE', "%$query%")
                      ->orWhereHas('subscriber', function($subQ) use ($query) {
                          $subQ->where('name', 'LIKE', "%$query%");
                      })
                      ->orWhereHas('meter', function($meterQ) use ($query) {
                          $meterQ->where('unique_code', 'LIKE', "%$query%")
                                 ->orWhere('path_number', $query);
                      });
                });
            }
        }

        if ($squareId) {
            $invoicesQuery->whereHas('subscriber', function($q) use ($squareId) {
                $q->where('square_id', $squareId);
            });
        }
        
        $invoices = $invoicesQuery->get();
            
        $invoices->each(function ($invoice) {
            $invoice->true_remaining_amount = $invoice->getTrueRemainingAmount();
        });

        // Add flag and sort
        if ($query) {
            if (preg_match('/^(\d+)[\/\-\\\\_](\d+)$/', $query)) {
                $invoices->each(function($invoice) {
                    $invoice->is_path_match = true;
                });
            } else {
                $invoices->each(function($invoice) use ($query) {
                    $invoice->is_path_match = $invoice->meter && (string)$invoice->meter->path_number === (string)$query;
                });
                $invoices = $invoices->sortByDesc('is_path_match')->values();
            }
        }
            
        return response()->json($invoices);
    }

    public function show(int $id)
    {
        $invoice = Invoice::with(['subscriber', 'meter', 'readings'])->find($id);
        
        if (!$invoice) {
            return response()->json(['message' => 'الفاتورة غير موجودة'], 404);
        }
        
        $invoice->true_remaining_amount = $invoice->getTrueRemainingAmount();
        
        return response()->json($invoice);
    }

    public function pay(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string',
            'reference_number' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $invoice = Invoice::find($id);
        if (!$invoice) {
            return response()->json(['message' => 'الفاتورة غير موجودة'], 404);
        }

        $amountToPay = $request->amount;
        $remainingAmount = $invoice->getTrueRemainingAmount();

        DB::beginTransaction();
        try {
            $paymentService = new \App\Services\PaymentService();
            $payment = $paymentService->collectPayment(
                $invoice->subscriber,
                $amountToPay,
                $invoice->id,
                $request->payment_method,
                $request->reference_number,
                $request->notes,
                null,
                $request->user()->id
            );

            DB::commit();
            $invoice->refresh();

            $receiptData = [
                'receipt_number' => $payment->receipt_number,
                'amount' => number_format((float)$payment->amount, 2, '.', ''),
                'date' => $payment->created_at->format('Y-m-d H:i'),
                'payment_method' => $payment->payment_method_label ?? $payment->payment_method,
                'subscriber_name' => $invoice->subscriber->name ?? '',
                'subscriber_phone' => $invoice->subscriber->phone ?? '',
                'invoice_remaining' => number_format((float)$invoice->getTrueRemainingAmount(), 2, '.', ''),
                'outstanding_balance' => number_format((float)($invoice->subscriber->getOutstandingBalance() + $invoice->subscriber->meters->sum(fn($m) => $m->getOutstandingBalance())), 2, '.', ''),
                'credit_balance' => number_format((float)(($invoice->subscriber->balance > 0 ? $invoice->subscriber->balance : 0) + $invoice->subscriber->meters->sum(fn($m) => $m->balance > 0 ? $m->balance : 0)), 2, '.', ''),
                'collector' => $request->user()->name ?? '',
                'pdf_url' => url('/payments/' . $payment->id . '/print'),
                'message_template' => \App\Models\Setting::get('payment_receipt_message_template', null),
            ];

            return response()->json([
                'message' => 'تم تسجيل الدفعة بنجاح',
                'payment' => $payment,
                'invoice_status' => $invoice->status,
                'receipt_data' => $receiptData
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment Error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'حدث خطأ أثناء معالجة الدفع.'], 500);
        }
    }
}

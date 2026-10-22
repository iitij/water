<?php

use App\Models\Subscriber;
use App\Models\Invoice;
use App\Services\PaymentService;

$ps = new PaymentService();
$ps->recalculateInvoices(Subscriber::find(53));

dump('INVOICES:', Invoice::where('subscriber_id', 53)->get(['id', 'bill_number', 'total_amount', 'amount_paid', 'status'])->toArray());

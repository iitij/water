<?php

use App\Models\Subscriber;
use App\Services\PaymentService;

$ps = new PaymentService();
$ps->recalculateInvoices(Subscriber::find(53));

echo "Recalculation done for 53!\n";

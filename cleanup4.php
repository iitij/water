<?php

$remaining = 700;

// Apply to INV-0050 (id 58)
$inv50 = App\Models\Invoice::find(58);
if ($inv50) {
    $due = (float)$inv50->total_amount - (float)$inv50->amount_paid;
    if ($due > 0) {
        $applied = min($remaining, $due);
        $inv50->increment('amount_paid', $applied);
        $remaining -= $applied;
    }
}

// Apply to INV-0052 (id 60)
$inv52 = App\Models\Invoice::find(60);
if ($inv52 && $remaining > 0) {
    $due = (float)$inv52->total_amount - (float)$inv52->amount_paid;
    if ($due > 0) {
        $applied = min($remaining, $due);
        $inv52->increment('amount_paid', $applied);
        $remaining -= $applied;
    }
}

App\Models\Invoice::all()->each->updateStatus();

echo "Manual distribution complete!\n";

<?php

// Isolated staging/testing switches; never enable in production until all
// financial entry points share the same gate and client contract.
return [
    'treasury_transfers' => env('WATER_CANDIDATE_TREASURY_TRANSFERS', false),
    'refunds' => env('WATER_CANDIDATE_REFUNDS', false),
    'bank_fees' => env('WATER_CANDIDATE_BANK_FEES', false),
    'cash_handovers' => env('WATER_CANDIDATE_CASH_HANDOVERS', false),
    'late_cash_settlements' => env('WATER_CANDIDATE_LATE_CASH_SETTLEMENTS', false),
    'noncash_settlements' => env('WATER_CANDIDATE_NONCASH_SETTLEMENTS', false),
    'cash_receipts' => env('WATER_CANDIDATE_CASH_RECEIPTS', false),
    'void_api' => env('WATER_CANDIDATE_VOID_API', false),
];

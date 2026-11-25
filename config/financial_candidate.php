<?php

// Isolated staging/testing switches; never enable in production until all
// financial entry points share the same gate and client contract.
return [
    'cash_handovers' => env('WATER_CANDIDATE_CASH_HANDOVERS', false),
    'late_cash_settlements' => env('WATER_CANDIDATE_LATE_CASH_SETTLEMENTS', false),
    'noncash_settlements' => env('WATER_CANDIDATE_NONCASH_SETTLEMENTS', false),
    'cash_receipts' => env('WATER_CANDIDATE_CASH_RECEIPTS', false),
    'void_api' => env('WATER_CANDIDATE_VOID_API', false),
];

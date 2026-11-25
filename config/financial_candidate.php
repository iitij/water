<?php

// Isolated staging/testing switches; never enable in production until all
// financial entry points share the same gate and client contract.
return [
    'cash_receipts' => env('WATER_CANDIDATE_CASH_RECEIPTS', false),
    'void_api' => env('WATER_CANDIDATE_VOID_API', false),
];

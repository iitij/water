<?php

// Isolated staging/testing switches; never enable in production until all
// financial entry points share the same gate and client contract.
return [
    'completed_cash_refund' => env('WATER_CANDIDATE_COMPLETED_CASH_REFUND', false),
    // Empty by default. Reviewed deployment must provision external Ed25519 public keys only.
    'refund_source_public_keys' => [],
    // Read-only archive: issuer => SHA-256 of raw public key => canonical base64 public key.
    // Deployment-controlled; never sufficient to establish current trust or authorize a posting.
    'refund_source_archived_public_keys' => [],
    'active_held_release' => env('WATER_CANDIDATE_ACTIVE_HELD_RELEASE', false),
    'invoices' => env('WATER_CANDIDATE_INVOICES', false),
    'subscriber_transfers' => env('WATER_CANDIDATE_SUBSCRIBER_TRANSFERS', false),
    'treasury_opening_funding' => env('WATER_CANDIDATE_TREASURY_OPENING_FUNDING', false),
    'treasury_transfers' => env('WATER_CANDIDATE_TREASURY_TRANSFERS', false),
    'refunds' => env('WATER_CANDIDATE_REFUNDS', false),
    'cash_count_adjustments' => env('WATER_CANDIDATE_CASH_COUNT_ADJUSTMENTS', false),
    'bank_evidence' => env('WATER_CANDIDATE_BANK_EVIDENCE', false),
    'bank_fees' => env('WATER_CANDIDATE_BANK_FEES', false),
    'cash_handovers' => env('WATER_CANDIDATE_CASH_HANDOVERS', false),
    'late_cash_settlements' => env('WATER_CANDIDATE_LATE_CASH_SETTLEMENTS', false),
    'noncash_settlements' => env('WATER_CANDIDATE_NONCASH_SETTLEMENTS', false),
    'collection_evidence' => env('WATER_CANDIDATE_COLLECTION_EVIDENCE', false),
    'cash_session_lifecycle' => env('WATER_CANDIDATE_CASH_SESSION_LIFECYCLE', false),
    'cash_receipts' => env('WATER_CANDIDATE_CASH_RECEIPTS', false),
    'void_api' => env('WATER_CANDIDATE_VOID_API', false),
];

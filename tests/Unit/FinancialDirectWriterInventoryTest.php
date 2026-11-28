<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FinancialWriterCoverageDiagnostic;
use PHPUnit\Framework\TestCase;

final class FinancialDirectWriterInventoryTest extends TestCase
{
    public function test_direct_writer_families_are_reported_even_without_optional_flags(): void
    {
        $service = new FinancialWriterCoverageDiagnostic();
        $rows = $service->directWriterInventory([]);
        self::assertSame([
            'held_cash_assignment', 'held_cash_payout_authorization',
            'held_cash_payout_cancellation', 'held_cash_payout', 'held_cash_credit',
            'custody_shortage_recovery_proposal', 'custody_shortage_recovery_approval',
            'custody_shortage_loss_proposal_and_approval',
        ], array_column($rows, 'family'));
        foreach ($rows as $row) {
            self::assertFalse($row['listed_guards_active']);
            self::assertSame($row['required_guards'], $row['missing_guards']);
            self::assertFalse($row['exclusive_entrypoints_verified']);
        }
    }

    public function test_guard_presence_is_not_exclusive_writer_proof_and_missing_guard_is_localized(): void
    {
        $service = new FinancialWriterCoverageDiagnostic();
        $guards = [];
        foreach ($service->directWriterInventory([]) as $row) {
            foreach ($row['required_guards'] as $guard) $guards[$guard] = true;
        }
        foreach ($service->directWriterInventory($guards) as $row) {
            self::assertTrue($row['listed_guards_active']);
            self::assertFalse($row['exclusive_entrypoints_verified']);
        }
        $guards['custody_shortage_recoveries'] = false;
        $guards['held_registry_links'] = 1;
        $byFamily = array_column($service->directWriterInventory($guards), null, 'family');
        self::assertSame(['custody_shortage_recoveries'], $byFamily['custody_shortage_recovery_approval']['missing_guards']);
        self::assertTrue($byFamily['custody_shortage_recovery_proposal']['listed_guards_active']);
        self::assertSame(['held_registry_links'], $byFamily['held_cash_credit']['missing_guards']);
        self::assertFalse($byFamily['held_cash_payout']['listed_guards_active']);
    }
}

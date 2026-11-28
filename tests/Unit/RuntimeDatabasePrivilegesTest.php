<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RuntimeDatabasePrivileges;
use PHPUnit\Framework\TestCase;

final class RuntimeDatabasePrivilegesTest extends TestCase
{
    private const USAGE = "GRANT USAGE ON *.* TO 'runtime'@'localhost'";

    public function test_active_or_unverifiable_session_roles_are_not_accepted(): void
    {
        $service = new RuntimeDatabasePrivileges();
        self::assertTrue($service->noActiveRoles('NONE'));
        self::assertTrue($service->noActiveRoles(null));
        foreach (['', false, 'admin_role', 'readonly_role', 'role_a,role_b', 'NONE,admin_role'] as $roles) {
            self::assertFalse($service->noActiveRoles($roles));
        }
    }

    public function test_accepts_exact_dml_scopes_without_global_access(): void
    {
        $service = new RuntimeDatabasePrivileges();
        foreach ([
            "GRANT SELECT, INSERT, UPDATE, DELETE ON \x60water\\_test\x60.* TO \x60runtime\x60@\x60%\x60",
            "GRANT SELECT, INSERT ON \x60water_test\x60.\x60transactions\x60 TO 'runtime'@'localhost'",
        ] as $grant) {
            self::assertSame(['accepted' => true, 'issues' => []], $service->evaluate('water_test', [self::USAGE, $grant]));
        }
    }

    public function test_only_named_read_only_metadata_routine_execute_is_allowed(): void
    {
        $service = new RuntimeDatabasePrivileges();
        $safe = "GRANT SELECT ON \x60water_test\x60.\x60payments\x60 TO 'runtime'@'localhost'";
        $routine = "GRANT EXECUTE ON PROCEDURE \x60water_test\x60.\x60financial_read_trigger_metadata\x60 TO 'runtime'@'localhost'";
        self::assertTrue($service->evaluate('water_test', [self::USAGE, $safe, $routine])['accepted']);
        foreach ([str_replace('financial_read_trigger_metadata', 'other_proc', $routine), $routine.' WITH GRANT OPTION',
            str_replace('water_test', 'other', $routine)] as $bad) {
            self::assertFalse($service->evaluate('water_test', [self::USAGE, $safe, $bad])['accepted']);
        }
    }

    public function test_only_exact_schema_lock_tables_is_allowed_without_close_control_dml(): void
    {
        $service = new RuntimeDatabasePrivileges();
        $safe = "GRANT SELECT, INSERT ON \x60water_test\x60.\x60payments\x60 TO 'runtime'@'localhost'";
        $locking = "GRANT LOCK TABLES ON \x60water\\_test\x60.* TO 'runtime'@'localhost'";
        self::assertTrue($service->evaluate('water_test', [self::USAGE, $safe, $locking])['accepted']);
        self::assertTrue($service->evaluateCloseControlIsolation('water_test', [self::USAGE, $safe, $locking])['accepted']);
        foreach ([
            "GRANT LOCK TABLES ON *.* TO 'runtime'@'localhost'",
            "GRANT LOCK TABLES ON \x60water_test\x60.* TO 'runtime'@'localhost'",
            "GRANT LOCK TABLES ON \x60other\x60.* TO 'runtime'@'localhost'",
            $locking.' WITH GRANT OPTION',
            str_replace('LOCK TABLES', 'LOCK TABLES, UPDATE', $locking),
        ] as $bad) {
            self::assertFalse($service->evaluate('water_test', [self::USAGE, $safe, $bad])['accepted']);
            self::assertFalse($service->evaluateCloseControlIsolation('water_test', [self::USAGE, $safe, $bad])['accepted']);
        }
    }

    public function test_close_control_isolation_rejects_broad_and_direct_writes(): void
    {
        $service = new RuntimeDatabasePrivileges();
        $ordinary = "GRANT SELECT, INSERT, UPDATE, DELETE ON \x60water_test\x60.\x60payments\x60 TO 'runtime'@'localhost'";
        $read = "GRANT SELECT ON \x60water\\_test\x60.* TO 'runtime'@'localhost'";
        $routine = "GRANT EXECUTE ON PROCEDURE \x60water_test\x60.\x60financial_read_trigger_metadata\x60 TO 'runtime'@'localhost'";
        $safe = [self::USAGE, $ordinary, $read, $routine];
        self::assertTrue($service->evaluateCloseControlIsolation('water_test', $safe)['accepted']);
        foreach (['financial_scopes', 'financial_periods', 'financial_close_commands', 'financial_period_closures'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $privilege) {
                $grant = "GRANT {$privilege} ON \x60water_test\x60.\x60{$table}\x60 TO 'runtime'@'localhost'";
                self::assertTrue($service->evaluate('water_test', [...$safe, $grant])['accepted']);
                $result = $service->evaluateCloseControlIsolation('water_test', [...$safe, $grant]);
                self::assertFalse($result['accepted']);
                self::assertContains('runtime_close_control_table_write', $result['issues']);
            }
        }
        $broad = "GRANT SELECT, INSERT, UPDATE, DELETE ON \x60water\\_test\x60.* TO 'runtime'@'localhost'";
        self::assertTrue($service->evaluate('water_test', [self::USAGE, $broad])['accepted']);
        self::assertSame(['accepted' => false, 'issues' => ['runtime_close_control_schema_write']],
            $service->evaluateCloseControlIsolation('water_test', [self::USAGE, $broad]));
        self::assertFalse($service->evaluateCloseControlIsolation('water_test', [])['accepted']);
        self::assertFalse($service->evaluateCloseControlIsolation('water_test', [...$safe, str_replace('financial_read_trigger_metadata', 'other_proc', $routine)])['accepted']);
    }

    public function test_rejects_elevated_unknown_global_and_wildcard_grants(): void
    {
        $service = new RuntimeDatabasePrivileges();
        $safe = "GRANT SELECT ON \x60water_test\x60.\x60payments\x60 TO 'runtime'@'localhost'";
        foreach ([
            "GRANT ALL PRIVILEGES ON \x60water_test\x60.* TO 'runtime'@'localhost'",
            "GRANT TRIGGER ON \x60water_test\x60.* TO 'runtime'@'localhost'",
            "GRANT DROP ON \x60water_test\x60.* TO 'runtime'@'localhost'",
            "GRANT CREATE ON \x60water_test\x60.* TO 'runtime'@'localhost'",
            "GRANT ALTER ON \x60water_test\x60.* TO 'runtime'@'localhost'",
            "GRANT SELECT ON *.* TO 'runtime'@'localhost'",
            "GRANT SELECT ON \x60water_test\x60.* TO 'runtime'@'localhost'",
            "GRANT SELECT ON \x60water%\x60.* TO 'runtime'@'localhost'",
            "GRANT SELECT ON \x60other\x60.\x60payments\x60 TO 'runtime'@'localhost'",
            "GRANT SELECT ON \x60water_test\x60.\x60payments\x60 TO 'runtime'@'localhost' WITH GRANT OPTION",
            "GRANT \x60admin_role\x60@\x60%\x60 TO 'runtime'@'localhost'",
            "GRANT PROXY ON 'root'@'localhost' TO 'runtime'@'localhost'",
            "GRANT USAGE ON *.* TO 'runtime'@'localhost' IDENTIFIED BY PASSWORD 'private-hash'",
        ] as $bad) {
            $result = $service->evaluate('water_test', [self::USAGE, $safe, $bad]);
            self::assertFalse($result['accepted']);
            self::assertNotEmpty($result['issues']);
            self::assertStringNotContainsString('private-hash', json_encode($result));
        }
        self::assertFalse($service->evaluate('water_test', [self::USAGE])['accepted']);
        self::assertFalse($service->evaluate('water_test', [])['accepted']);
    }
}

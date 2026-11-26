<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RuntimeDatabasePrivileges;
use PHPUnit\Framework\TestCase;

final class RuntimeDatabasePrivilegesTest extends TestCase
{
    private const USAGE = "GRANT USAGE ON *.* TO 'runtime'@'localhost'";

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

    public function test_mariadb_native_password_metadata_only_qualifies_a_global_usage_grant(): void
    {
        $service = new RuntimeDatabasePrivileges();
        $suffix = " IDENTIFIED BY PASSWORD '*".str_repeat('A', 40)."'";
        $usage = self::USAGE.$suffix;
        $dml = "GRANT SELECT, INSERT, UPDATE, DELETE ON `water\\_test`.* TO 'runtime'@'localhost'";
        $routine = "GRANT EXECUTE ON PROCEDURE `water_test`.`financial_read_trigger_metadata` TO 'runtime'@'localhost'";
        self::assertSame(['accepted' => true, 'issues' => []], $service->evaluate('water_test', [$usage, $dml, $routine]));
        self::assertFalse($service->evaluate('water_test', [$usage])['accepted']);

        foreach ([
            $usage.' WITH GRANT OPTION',
            $usage.' REQUIRE SSL',
            $dml.$suffix,
            $routine.$suffix,
            "GRANT EXECUTE ON `water\\_test`.* TO 'runtime'@'localhost'",
            str_replace('GRANT USAGE', 'GRANT SELECT', $usage),
        ] as $unexpected) {
            $result = $service->evaluate('water_test', [$usage, $dml, $unexpected]);
            self::assertFalse($result['accepted']);
            self::assertStringNotContainsString(str_repeat('A', 40), json_encode($result));
        }
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

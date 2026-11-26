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

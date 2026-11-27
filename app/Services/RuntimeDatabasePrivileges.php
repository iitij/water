<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Conservative grant allowlist, not a proof that raw DML is authorized. */
final class RuntimeDatabasePrivileges
{
    public function inspect(): array
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return ['accepted' => false, 'issues' => ['runtime_mysql_required'],
                'close_control_write_isolated' => false, 'close_control_issues' => ['runtime_mysql_required']];
        }
        $pdo = DB::connection()->getPdo();
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(\PDO::FETCH_COLUMN);
        $result = $this->evaluate((string) $database, $grants);
        // SHOW GRANTS alone is insufficient where server-mandatory roles
        // or session roles add effective rights to the runtime identity.
        $roles = $pdo->query('SELECT CURRENT_ROLE()')->fetchColumn();
        if (! $this->noActiveRoles($roles)) {
            $result['accepted'] = false;
            $result['issues'][] = 'runtime_active_roles_unverified';
        }
        if (! app(FinancialConnectionIntegrity::class)->active()) {
            $result['accepted'] = false;
            $result['issues'][] = 'runtime_connection_constraints_disabled';
        }
        $isolation = $this->evaluateCloseControlIsolation((string) $database, $grants);
        $result['close_control_write_isolated'] = $result['accepted'] && $isolation['accepted'];
        $result['close_control_issues'] = array_values(array_unique(array_merge($result['issues'], $isolation['issues'])));
        return $result;
    }

    /** Separate operational identity from the privileged close coordinator.
     * A broad DML allowlist is deliberately insufficient for this check. */
    public function evaluateCloseControlIsolation(string $database, array $grants): array
    {
        $base = $this->evaluate($database, $grants);
        $issues = $base['issues'];
        $protected = ['financial_scopes', 'financial_periods', 'financial_close_commands', 'financial_period_closures'];
        foreach ($grants as $grant) {
            if (! is_string($grant) || ! preg_match('/\\AGRANT ([A-Z ,]+) ON (\\S+) TO /', trim($grant), $parts)) continue;
            $privileges = array_map('trim', explode(',', $parts[1]));
            if (array_intersect($privileges, ['INSERT', 'UPDATE', 'DELETE']) === []) continue;
            $scope = $parts[2];
            if (str_ends_with($scope, '.*')) {
                $issues[] = 'runtime_close_control_schema_write';
                continue;
            }
            $table = strtolower(trim(substr($scope, strrpos($scope, '.') + 1), chr(96)));
            if (in_array($table, $protected, true)) $issues[] = 'runtime_close_control_table_write';
        }
        $issues = array_values(array_unique($issues));
        return ['accepted' => $issues === [], 'issues' => $issues];
    }

    public function noActiveRoles(mixed $roles): bool
    {
        // MySQL returns NONE; MariaDB may return SQL NULL.
        return $roles === null || $roles === 'NONE';
    }

    /** No raw grant strings are returned: MariaDB can include credential hashes. */
    public function evaluate(string $database, array $grants): array
    {
        $issues = [];
        $hasDml = false;
        if (! preg_match('/\A[A-Za-z0-9_]+\z/', $database) || $grants === []) {
            return ['accepted' => false, 'issues' => ['runtime_grants_unverifiable']];
        }
        $escapedSchema = str_replace('_', '\\_', $database);
        foreach ($grants as $grant) {
            if (is_string($grant) && preg_match('/\AGRANT EXECUTE ON PROCEDURE \x60'.preg_quote($database, '/').'\x60\.\x60financial_read_trigger_metadata\x60 TO (?:\x60[^\x60]+\x60|\x27[^\x27]+\x27)@(?:\x60[^\x60]+\x60|\x27[^\x27]+\x27)\z/', trim($grant))) {
                continue;
            }
            if (! is_string($grant) || ! preg_match('/\AGRANT ([A-Z ,]+) ON (\S+) TO (?:\x60[^\x60]+\x60|\x27[^\x27]+\x27)@(?:\x60[^\x60]+\x60|\x27[^\x27]+\x27)\z/', trim($grant), $parts)) {
                $issues[] = 'runtime_grant_unrecognized_or_delegated';
                continue;
            }
            $privileges = array_map('trim', explode(',', $parts[1]));
            $scope = $parts[2];
            if ($privileges === ['USAGE'] && $scope === '*.*') continue;
            if (array_diff($privileges, ['SELECT', 'INSERT', 'UPDATE', 'DELETE']) !== []) {
                $issues[] = 'runtime_privilege_exceeds_dml';
                continue;
            }
            // Schema wildcard grants are unsafe with unescaped underscores.
            // Table grants use exact identifiers rather than database patterns.
            $schemaAllowed = $scope === chr(96).$escapedSchema.chr(96).'.*';
            $tableAllowed = preg_match('/\A\x60'.preg_quote($database, '/').'\x60\.\x60[A-Za-z0-9_]+\x60\z/', $scope) === 1;
            if (! $schemaAllowed && ! $tableAllowed) {
                $issues[] = 'runtime_grant_scope_not_exact';
                continue;
            }
            $hasDml = true;
        }
        if (! $hasDml) $issues[] = 'runtime_dml_grant_missing';
        return ['accepted' => $issues === [], 'issues' => array_values(array_unique($issues))];
    }
}

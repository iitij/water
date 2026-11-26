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
            return ['accepted' => false, 'issues' => ['runtime_mysql_required']];
        }
        $pdo = DB::connection()->getPdo();
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(\PDO::FETCH_COLUMN);
        return $this->evaluate((string) $database, $grants);
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

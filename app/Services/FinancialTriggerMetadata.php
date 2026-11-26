<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Live metadata, never an application-writable attestation or cached flag. */
final class FinancialTriggerMetadata
{
    public const VIEW = 'financial_trigger_metadata';

    public static function query(): Builder
    {
        // Do not fall back to a less privileged or stale source on failure.
        $source = config('database.financial_trigger_metadata_view') === true
            ? self::VIEW : 'information_schema.TRIGGERS';
        return DB::table($source)->whereRaw('TRIGGER_SCHEMA = DATABASE()');
    }

    /** Deployment only; runtime receives SELECT on the non-updatable view. */
    public function install(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new \LogicException('Financial metadata view requires MySQL or MariaDB.');
        }
        $pdo = DB::connection()->getPdo();
        $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (! is_string($schema) || $schema === '') {
            throw new \LogicException('Financial metadata schema is missing.');
        }
        $literal = $pdo->quote($schema);
        if ($literal === false) throw new \LogicException('Financial metadata schema cannot be quoted.');
        DB::unprepared('CREATE OR REPLACE ALGORITHM=TEMPTABLE SQL SECURITY DEFINER VIEW '.self::VIEW
            .' AS SELECT TRIGGER_SCHEMA, TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
            .' FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '.$literal);
        if (! $this->deploymentVerified()) throw new \LogicException('Financial trigger metadata view verification failed.');
    }

    /** Compare the live projection with the deployment principal's actual metadata. */
    public function deploymentVerified(): bool
    {
        $view = DB::table('information_schema.VIEWS')->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', self::VIEW)->first();
        if ($view === null || $view->SECURITY_TYPE !== 'DEFINER' || $view->IS_UPDATABLE !== 'NO') return false;
        $columns = ['TRIGGER_SCHEMA', 'TRIGGER_NAME', 'EVENT_OBJECT_TABLE', 'EVENT_MANIPULATION', 'ACTION_TIMING', 'ACTION_STATEMENT'];
        $actual = DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
            ->orderBy('TRIGGER_NAME')->get($columns)->all();
        $projected = DB::table(self::VIEW)->whereRaw('TRIGGER_SCHEMA = DATABASE()')
            ->orderBy('TRIGGER_NAME')->get($columns)->all();
        return $actual !== [] && $actual == $projected;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read live metadata on the same write connection; no cached attestation. */
final class FinancialTriggerMetadata
{
    public const ROUTINE = 'financial_read_trigger_metadata';

    public static function query(): Collection
    {
        if (config('database.financial_trigger_metadata_routine') === true) {
            return collect(self::routineRows());
        }
        return DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
            ->useWritePdo()->get();
    }

    private static function routineRows(): array
    {
        $statement = DB::connection()->getPdo()->prepare('CALL '.self::ROUTINE.'()');
        $statement->execute();
        try {
            return $statement->fetchAll(\PDO::FETCH_OBJ);
        } finally {
            $statement->closeCursor();
        }
    }

    public function install(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new \LogicException('Financial metadata routine requires MySQL or MariaDB.');
        }
        $pdo = DB::connection()->getPdo();
        $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (! is_string($schema) || $schema === '') throw new \LogicException('Financial metadata schema is missing.');
        $literal = $pdo->quote($schema);
        if ($literal === false) throw new \LogicException('Financial metadata schema cannot be quoted.');
        DB::unprepared('DROP PROCEDURE IF EXISTS '.self::ROUTINE);
        DB::unprepared('CREATE PROCEDURE '.self::ROUTINE.'() READS SQL DATA SQL SECURITY DEFINER'
            .' SELECT TRIGGER_SCHEMA, TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
            .' FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '.$literal.' ORDER BY TRIGGER_NAME');
        if (! $this->deploymentVerified()) throw new \LogicException('Financial metadata routine verification failed.');
    }

    public function deploymentVerified(): bool
    {
        $routine = DB::table('information_schema.ROUTINES')->whereRaw('ROUTINE_SCHEMA = DATABASE()')
            ->where('ROUTINE_NAME', self::ROUTINE)->first();
        if ($routine === null || $routine->ROUTINE_TYPE !== 'PROCEDURE'
            || $routine->SECURITY_TYPE !== 'DEFINER' || $routine->SQL_DATA_ACCESS !== 'READS SQL DATA') return false;
        $columns = ['TRIGGER_SCHEMA', 'TRIGGER_NAME', 'EVENT_OBJECT_TABLE', 'EVENT_MANIPULATION', 'ACTION_TIMING', 'ACTION_STATEMENT'];
        $actual = DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
            ->useWritePdo()->orderBy('TRIGGER_NAME')->get($columns)->all();
        return $actual !== [] && $actual == self::routineRows();
    }
}

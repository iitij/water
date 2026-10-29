<?php

namespace App\Support;

final class MobileLaravelChangeInventory
{
    /** This reviewed snapshot ships with the application; production does not need Git. */
    public static function snapshot(): array
    {
        $inventory = json_decode(
            file_get_contents(resource_path('data/laravel-mobile-changes.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $files = array_merge(...array_column($inventory['groups'], 'files'));
        $inventory['totals'] = [
            'files' => count($files),
            'added' => count(array_filter($files, fn (array $file): bool => $file['status'] === 'A')),
            'modified' => count(array_filter($files, fn (array $file): bool => $file['status'] === 'M')),
            'migrations' => count(array_filter($files, fn (array $file): bool => str_starts_with($file['path'], 'database/migrations/'))),
            'groups' => count($inventory['groups']),
        ];

        return $inventory;
    }
}

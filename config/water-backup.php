<?php

return [
    'policy_dir' => storage_path('app/private/backup-control'),
    'state_dir' => storage_path('backup-control'),
    'archive_dir' => dirname(base_path(), 2).'/.water-backups',
    'runner' => dirname(base_path(), 2).'/.water-backup-ops/water-backup-daily.sh',
];

<?php

return [
    'title' => 'Backups',
    'create' => 'Create backup',
    'name' => 'Name',
    'type' => 'Type',
    'schedule' => 'Schedule',
    'quota' => 'Backups: :used of :quota',
    'quota_exceeded' => 'The backup limit has been reached. Delete old backups.',
    'restore' => 'Restore',
    'restore_warning' => 'Restoring replaces all current server files. A safety backup is created first, but unsaved in-game progress will be lost.',
    'restore_confirm' => 'Are you sure? All current files will be replaced.',
    'download' => 'Download',
    'lock' => 'Protect from deletion',
    'unlock' => 'Remove protection',
    'locked' => 'This backup is protected from deletion',
    'empty' => 'No backups yet',
    'schedules' => [
        'hourly' => 'Every hour',
        'daily' => 'Every day',
        'weekly' => 'Every week',
        'monthly' => 'Every month',
    ],
    'types' => [
        'auto' => 'Automatic',
        'manual' => 'Manual',
        'pre_upgrade' => 'Before upgrade',
        'system' => 'System',
    ],
    'messages' => [
        'created' => 'Backup ":name" is being created',
        'deleted' => 'Backup deleted',
        'restoring' => 'Restoring from ":name" has started',
    ],
    'errors' => [
        'no_node' => 'The node is unavailable, backup is impossible',
        'locked' => 'This backup is protected from deletion',
        'restore_failed' => 'Restore failed: :error',
        'not_uploaded' => 'This copy has not been uploaded to cloud storage',
    ],
    'events' => [
        'creating' => 'Creating backup ":name"',
        'completed' => 'Backup ":name" is ready',
        'failed' => 'Backup ":name" failed',
        'restoring' => 'Restoring from backup ":name"',
        'deleted' => 'Backup ":name" deleted',
    ],
];

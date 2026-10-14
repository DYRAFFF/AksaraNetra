<?php

return [
    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', function () {
                $raw = env('BACKEND_DATA_DIR', 'data');
                $dir = (str_starts_with($raw, '/') || (strlen($raw) > 2 && $raw[1] === ':'))
                    ? $raw
                    : base_path($raw);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0777, true);
                }
                $file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'jobs.sqlite';
                if (!file_exists($file)) {
                    @touch($file);
                }
                return $file;
            }),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL',
            'options' => [
                PDO::ATTR_TIMEOUT => 5,
            ],
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];

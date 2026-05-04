<?php
declare(strict_types=1);

require_once __DIR__ . '/admin/config/config.php';

return [
    'paths' => [
        'migrations' => '_non_prod/db/migrations',
        'seeds' => '_non_prod/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => [
            'adapter' => 'mysql',
            'host' => defined('DB_HOST') ? DB_HOST : 'localhost',
            'name' => defined('DB_NAME') ? DB_NAME : '',
            'user' => defined('DB_USER') ? DB_USER : '',
            'pass' => defined('DB_PASS') ? DB_PASS : '',
            'port' => 3306,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
        'production' => [
            'adapter' => 'mysql',
            'host' => defined('DB_HOST') ? DB_HOST : 'localhost',
            'name' => defined('DB_NAME') ? DB_NAME : '',
            'user' => defined('DB_USER') ? DB_USER : '',
            'pass' => defined('DB_PASS') ? DB_PASS : '',
            'port' => 3306,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
    'version_order' => 'creation',
];

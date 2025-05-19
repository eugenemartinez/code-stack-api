<?php

// Load .env file
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad(); // Use safeLoad to avoid exceptions if .env is missing.

// Optional: For debugging, you can uncomment these lines to check if variables are loaded:
// echo 'DB_ADAPTER from $_ENV: ' . ($_ENV['DB_ADAPTER'] ?? 'NOT SET') . PHP_EOL;
// echo 'DB_HOST from $_ENV: ' . ($_ENV['DB_HOST'] ?? 'NOT SET') . PHP_EOL;
// echo 'DB_NAME from $_ENV: ' . ($_ENV['DB_NAME'] ?? 'NOT SET') . PHP_EOL;
// echo 'DB_USER from $_ENV: ' . ($_ENV['DB_USER'] ?? 'NOT SET') . PHP_EOL;
// // Be careful with echoing passwords, even in debug:
// // echo 'DB_PASS from $_ENV: ' . (isset($_ENV['DB_PASS']) ? 'SET' : 'NOT SET') . PHP_EOL;
// echo 'DB_PORT from $_ENV: ' . ($_ENV['DB_PORT'] ?? 'NOT SET') . PHP_EOL;
// die('Debug output. Comment out or remove this line to continue.');


return
[
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds'
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'production' => [ // For production, ensure these ENV vars are set in your production environment
            'adapter' => $_ENV['DB_ADAPTER_PROD'] ?? 'pgsql',
            'host' => $_ENV['DB_HOST_PROD'] ?? null, // Let Phinx error if not set
            'name' => $_ENV['DB_NAME_PROD'] ?? null, // Let Phinx error if not set
            'user' => $_ENV['DB_USER_PROD'] ?? null, // Let Phinx error if not set
            'pass' => $_ENV['DB_PASS_PROD'] ?? null, // Let Phinx error if not set
            'port' => $_ENV['DB_PORT_PROD'] ?? '5432',
            'charset' => $_ENV['DB_CHARSET_PROD'] ?? 'utf8',
        ],
        'development' => [
            // These will take values directly from your .env file via $_ENV
            // If any of these are not set in .env, the value will be null,
            // and Phinx will likely throw an error, which is desired behavior
            // as it indicates a missing configuration.
            'adapter' => $_ENV['DB_ADAPTER'] ?? null,
            'host' => $_ENV['DB_HOST'] ?? null,
            'name' => $_ENV['DB_NAME'] ?? null,
            'user' => $_ENV['DB_USER'] ?? null,
            'pass' => $_ENV['DB_PASS'] ?? null,
            'port' => $_ENV['DB_PORT'] ?? null,
            'charset' => $_ENV['DB_CHARSET'] ?? 'utf8', // Charset can have a safe default
        ],
        'testing' => [ // For testing, ensure these ENV vars are set, or use specific test DB settings
            'adapter' => $_ENV['DB_ADAPTER_TEST'] ?? 'pgsql',
            'host' => $_ENV['DB_HOST_TEST'] ?? null,
            'name' => $_ENV['DB_NAME_TEST'] ?? null,
            'user' => $_ENV['DB_USER_TEST'] ?? null,
            'pass' => $_ENV['DB_PASS_TEST'] ?? null,
            'port' => $_ENV['DB_PORT_TEST'] ?? '5432',
            'charset' => $_ENV['DB_CHARSET_TEST'] ?? 'utf8',
        ]
    ],
    'version_order' => 'creation'
];

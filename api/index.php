<?php
ini_set('display_errors', 0); // Crucial: Do not display errors in the output
ini_set('log_errors', 1);    // Recommended: Log errors instead
// Optional: Define a specific log file if your server doesn't have a default one you check
// ini_set('error_log', dirname(__DIR__) . '/logs/php_error.log'); // Ensure 'logs' dir exists and is writable
error_reporting(E_ALL);     // Report all errors (so they get logged)

use Slim\Factory\AppFactory;
// Psr\Http\Message\ResponseInterface and ServerRequestInterface are only needed if any route closure remains in index.php
// If all routes (including '/') are moved or are Actions, these can be removed from here.

// Define project root based on the location of this file
$projectRoot = dirname(__DIR__);

require $projectRoot . '/vendor/autoload.php';

// Load .env file conditionally
// In production (like Vercel), environment variables are set directly in the platform.
// .env file is primarily for local development.
if (file_exists($projectRoot . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($projectRoot);
    $dotenv->load();
}
// Or, if using phpdotenv v5+:
// $dotenv = Dotenv\Dotenv::createImmutable($projectRoot);
// $dotenv->safeLoad();


// --- DI Container Setup ---
// Assuming your dependencies.php returns a callable that expects $projectRoot
// and returns a configured container.
/** @var \Psr\Container\ContainerInterface $container */
$container = (require __DIR__ . '/config/dependencies.php')($projectRoot);

// Set container to create App with on AppFactory
AppFactory::setContainer($container);
$app = AppFactory::create();

// --- Middleware Configuration ---
// Assuming your middleware.php returns a callable that expects the App instance.
(require __DIR__ . '/config/middleware.php')($app);

// --- Route Definitions ---
// Assuming your routes.php returns a callable that expects the App instance.
(require __DIR__ . '/config/routes.php')($app);

// Run the app
$app->run();
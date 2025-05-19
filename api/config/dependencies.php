<?php

use DI\Container;
use Psr\Container\ContainerInterface;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Processor\UidProcessor;
use Psr\Log\LoggerInterface;
use Monolog\Level;
use HTMLPurifier;
use HTMLPurifier_Config;
use PDO; // Make sure PDO is imported if not already

// Action Use Statements
use App\Actions\Snippets\CreateSnippetAction;
// use App\Actions\Snippets\GetAllSnippetsAction; // This was the incorrect one, remove if present
use App\Actions\Snippets\ListSnippetsAction;   // Correct one for listing
use App\Actions\Snippets\GetSnippetAction;
use App\Actions\Snippets\UpdateSnippetAction;
use App\Actions\Snippets\DeleteSnippetAction;

return function (string $projectRoot): Container {
    $container = new Container();

    // --- Logger Setup ---
    $container->set(LoggerInterface::class, function () use ($projectRoot) {
        $logLevel = ($_ENV['DEBUG_MODE'] ?? 'false') === 'true' ? Level::Debug : Level::Info;
        $logger = new Logger('codestack_api');
        $logger->pushProcessor(new UidProcessor());
        $logFilePath = $projectRoot . '/logs/api.log';
        $rotatingHandler = new RotatingFileHandler($logFilePath, 7, $logLevel);
        $logger->pushHandler($rotatingHandler);
        return $logger;
    });
    $container->set('logger', \DI\get(LoggerInterface::class));


    // --- HTMLPurifier Setup ---
    $container->set(HTMLPurifier::class, function () use ($projectRoot) {
        $config = HTMLPurifier_Config::createDefault();
        $cachePath = $projectRoot . '/logs/htmlpurifier_cache';
        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0775, true);
        }
        $config->set('Cache.SerializerPath', $cachePath);
        $config->set('Cache.SerializerPermissions', 0775);
        $config->set('HTML.AllowedElements', ['div', 'span', 'br']);
        $config->set('HTML.AllowedAttributes', ['*.class', 'span.style', 'div.style']);
        $config->set('CSS.AllowedProperties', ['color', 'background-color', 'font-weight', 'font-style', 'text-decoration']);
        $config->set('HTML.SafeEmbed', false);
        $config->set('HTML.SafeObject', false);
        $config->set('HTML.SafeIframe', false);
        $config->set('Output.FlashCompat', false);
        $config->set('HTML.TidyLevel', 'medium');
        return new HTMLPurifier($config);
    });


    // --- Database Connection ---
    $container->set('db', function () use ($projectRoot) {
        // Determine if we are in a testing environment
        $isTesting = ($_ENV['TESTING'] ?? 'false') === 'true' || strtolower($_ENV['APP_ENV'] ?? '') === 'testing';

        $logger = null; // Initialize logger variable
        // Attempt to get logger if available, for logging potential config issues
        // Note: 'this' inside a PHP-DI factory refers to the container itself.
        // We need to check if the factory is being executed within a container context that has LoggerInterface.
        // For simplicity, we'll assume it might not be available during early DI setup for this specific check.

        if ($isTesting) {
            // Use test database configuration
            $dbHost = $_ENV['DB_HOST_TEST'] ?? null;
            $dbName = $_ENV['DB_NAME_TEST'] ?? null;
            $dbUser = $_ENV['DB_USER_TEST'] ?? null;
            $dbPass = $_ENV['DB_PASS_TEST'] ?? null;
            $dbPort = $_ENV['DB_PORT_TEST'] ?? '5432'; // Default PostgreSQL port

            if (empty($dbName) || empty($dbHost) || empty($dbUser)) {
                $errorMessage = 'Critical test database configuration (DB_HOST_TEST, DB_NAME_TEST, DB_USER_TEST) is missing from the environment.';
                error_log($errorMessage); // Log to general error log
                // Optionally, try to use the application logger if it's already configured and available
                // if ($this->has(LoggerInterface::class)) { $this->get(LoggerInterface::class)->critical($errorMessage); }
                throw new \RuntimeException($errorMessage);
            }
        } else {
            // Not testing: could be development or production
            $appEnv = strtolower($_ENV['APP_ENV'] ?? 'development'); // Default to 'development'

            if ($appEnv === 'production') {
                // Production environment: use _PROD suffixed variables
                $dbHost = $_ENV['DB_HOST_PROD'] ?? null;
                $dbName = $_ENV['DB_NAME_PROD'] ?? null;
                $dbUser = $_ENV['DB_USER_PROD'] ?? null;
                $dbPass = $_ENV['DB_PASS_PROD'] ?? null;
                $dbPort = $_ENV['DB_PORT_PROD'] ?? '5432'; // Default PostgreSQL port

                if (empty($dbName) || empty($dbHost) || empty($dbUser)) {
                    $errorMessage = 'Critical production database configuration (DB_HOST_PROD, DB_NAME_PROD, DB_USER_PROD) is missing from the environment.';
                    error_log($errorMessage);
                    throw new \RuntimeException($errorMessage);
                }
            } else {
                // Development environment (or any other non-testing, non-production environment)
                // Use non-suffixed variables
                $dbHost = $_ENV['DB_HOST'] ?? null;
                $dbName = $_ENV['DB_NAME'] ?? null;
                $dbUser = $_ENV['DB_USER'] ?? null;
                $dbPass = $_ENV['DB_PASS'] ?? null;
                $dbPort = $_ENV['DB_PORT'] ?? '5432'; // Default PostgreSQL port

                if (empty($dbName) || empty($dbHost) || empty($dbUser)) {
                    $errorMessage = 'Critical database configuration (DB_HOST, DB_NAME, DB_USER) is missing from the environment.';
                    error_log($errorMessage);
                    throw new \RuntimeException($errorMessage);
                }
            }
        }

        // Construct DSN for PostgreSQL
        // User and password should NOT be in the DSN string for PDO with pgsql,
        // they are passed as separate arguments to the PDO constructor.
        $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
        
        try {
            $pdo = new PDO($dsn, $dbUser, $dbPass); // Pass user and pass separately
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        } catch (\PDOException $e) {
            // Log the specific DSN or connection details (without password) if possible
            $safeDsnForLog = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName};user={$dbUser}";
            error_log("Database connection failed in DI container for DSN: {$safeDsnForLog}. Error: " . $e->getMessage());
            // if ($this->has(LoggerInterface::class)) { $this->get(LoggerInterface::class)->error("Database connection failed for DSN: {$safeDsnForLog}", ['exception' => $e]); }
            throw $e; // Re-throw the exception after logging
        }
    });
    $container->set(PDO::class, \DI\get('db'));


    // --- Action Factories ---
    $container->set(CreateSnippetAction::class, function (ContainerInterface $c) {
        return new CreateSnippetAction(
            $c->get('db'),
            $c->get(HTMLPurifier::class),
            $c->get(LoggerInterface::class)
        );
    });

    // Use ListSnippetsAction here
    $container->set(ListSnippetsAction::class, function (ContainerInterface $c) {
        return new ListSnippetsAction(
            $c->get('db'),
            $c->get(LoggerInterface::class)
        );
    });

    $container->set(GetSnippetAction::class, function (ContainerInterface $c) {
        return new GetSnippetAction($c->get('db'), $c->get(LoggerInterface::class));
    });

    $container->set(UpdateSnippetAction::class, function (ContainerInterface $c) {
        return new UpdateSnippetAction(
            $c->get('db'),
            $c->get(HTMLPurifier::class),
            $c->get(LoggerInterface::class)
        );
    });

    $container->set(DeleteSnippetAction::class, function (ContainerInterface $c) {
        return new DeleteSnippetAction(
            $c->get('db'),
            $c->get(LoggerInterface::class)
        );
    });

    return $container;
};
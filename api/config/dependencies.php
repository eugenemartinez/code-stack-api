<?php

use DI\Container;
use Psr\Container\ContainerInterface;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler; // Added for stdout logging option
use Monolog\Processor\UidProcessor;
use Psr\Log\LoggerInterface;
use Monolog\Level;
use Monolog\Formatter\LineFormatter; // Added for better log formatting
use HTMLPurifier;
use HTMLPurifier_Config;
use PDO;

// Action Use Statements
use App\Actions\Snippets\CreateSnippetAction;
use App\Actions\Snippets\ListSnippetsAction;
use App\Actions\Snippets\GetSnippetAction;
use App\Actions\Snippets\UpdateSnippetAction;
use App\Actions\Snippets\DeleteSnippetAction;

return function (string $projectRoot): Container {
    $container = new Container();

    // Determine application environment
    $appEnv = strtolower($_ENV['APP_ENV'] ?? 'development');
    $isDebug = strtolower($_ENV['DEBUG_MODE'] ?? 'false') === 'true';

    // --- Logger Setup ---
    $container->set(LoggerInterface::class, function () use ($projectRoot, $appEnv, $isDebug) {
        $logger = new Logger('codestack_api');
        $logger->pushProcessor(new UidProcessor());

        $handler = null;
        $logLevel = $isDebug ? Level::Debug : Level::Info;

        if ($appEnv === 'production' || $appEnv === 'testing_on_vercel') { // Vercel or similar production
            // Option 1: Log to stdout/stderr (Recommended for Vercel)
            // Vercel captures stdout/stderr and makes it available in its logs.
            // Use 'php://stdout' for general logs, 'php://stderr' for errors if separating.
            $handler = new StreamHandler('php://stdout', $logLevel);
            
            // Option 2: Log to /tmp (if you prefer file-based for some reason, but less ideal for Vercel)
            // $logFilePath = '/tmp/api.log';
            // $handler = new RotatingFileHandler($logFilePath, 7, $logLevel);
        } else {
            // Local development: log to a file in project_root/logs
            $logsDir = $projectRoot . '/logs';
            if (!is_dir($logsDir)) {
                // Attempt to create the directory
                if (!mkdir($logsDir, 0775, true) && !is_dir($logsDir)) {
                    // Fallback if directory creation fails (e.g., permissions)
                    error_log("Warning: Could not create logs directory: {$logsDir}. Logging will be disabled or use a fallback.");
                    // Optionally, you could throw an exception or use a null handler
                    // For now, let's try to proceed, RotatingFileHandler might fail gracefully or throw
                }
            }
            $logFilePath = $logsDir . '/api.log';
            $handler = new RotatingFileHandler($logFilePath, 7, $logLevel);
        }
        
        if ($handler) {
            // Set formatter for better readability
            $formatter = new LineFormatter(
                "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                "Y-m-d H:i:s.u", // Date format
                true, // Allow inline line breaks
                true  // Ignore empty context and extra
            );
            $handler->setFormatter($formatter);
            $logger->pushHandler($handler);
        }

        return $logger;
    });
    $container->set('logger', \DI\get(LoggerInterface::class));


    // --- HTMLPurifier Setup ---
    $container->set(HTMLPurifier::class, function () use ($projectRoot, $appEnv) {
        $config = HTMLPurifier_Config::createDefault();
        
        $cachePath = '';
        if ($appEnv === 'production' || $appEnv === 'testing_on_vercel') { // Vercel or similar production
            $cachePath = '/tmp/htmlpurifier_cache'; // Use /tmp for writable storage
            // Attempt to create /tmp/htmlpurifier_cache if it doesn't exist.
            // This might be necessary if HTMLPurifier doesn't create it automatically in /tmp.
            if (!is_dir($cachePath)) {
                mkdir($cachePath, 0775, true); // Vercel /tmp is writable
            }
        } else {
            // Local development
            $cachePath = $projectRoot . '/logs/htmlpurifier_cache';
            if (!is_dir($cachePath)) {
                mkdir($cachePath, 0775, true);
            }
        }
        
        $config->set('Cache.SerializerPath', $cachePath);
        $config->set('Cache.SerializerPermissions', 0775); // Permissions for the cache files
        
        // Your existing HTMLPurifier configuration
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
    $container->set('db', function () use ($projectRoot, $appEnv) { // Pass $appEnv here
        // Determine if we are in a testing environment
        $isTesting = ($_ENV['TESTING'] ?? 'false') === 'true' || $appEnv === 'testing';

        if ($isTesting) {
            // Use test database configuration
            $dbHost = $_ENV['DB_HOST_TEST'] ?? null;
            $dbName = $_ENV['DB_NAME_TEST'] ?? null;
            $dbUser = $_ENV['DB_USER_TEST'] ?? null;
            $dbPass = $_ENV['DB_PASS_TEST'] ?? null;
            $dbPort = $_ENV['DB_PORT_TEST'] ?? '5432';
            $sslmode = 'prefer';

            if (empty($dbName) || empty($dbHost) || empty($dbUser)) {
                $errorMessage = 'Critical test database configuration (DB_HOST_TEST, DB_NAME_TEST, DB_USER_TEST) is missing from the environment.';
                error_log($errorMessage);
                throw new \RuntimeException($errorMessage);
            }
        } else {
            if ($appEnv === 'production' || $appEnv === 'testing_on_vercel') { // Added testing_on_vercel here too
                $dbHost = $_ENV['DB_HOST_PROD'] ?? null;
                $dbName = $_ENV['DB_NAME_PROD'] ?? null;
                $dbUser = $_ENV['DB_USER_PROD'] ?? null;
                $dbPass = $_ENV['DB_PASS_PROD'] ?? null;
                $dbPort = $_ENV['DB_PORT_PROD'] ?? '5432';
                $sslmode = 'require';

                if (empty($dbName) || empty($dbHost) || empty($dbUser)) {
                    $errorMessage = 'Critical production database configuration (DB_HOST_PROD, DB_NAME_PROD, DB_USER_PROD) is missing from the environment.';
                    error_log($errorMessage);
                    throw new \RuntimeException($errorMessage);
                }
            } else {
                // Development environment
                $dbHost = $_ENV['DB_HOST'] ?? null;
                $dbName = $_ENV['DB_NAME'] ?? null;
                $dbUser = $_ENV['DB_USER'] ?? null;
                $dbPass = $_ENV['DB_PASS'] ?? null;
                $dbPort = $_ENV['DB_PORT'] ?? '5432';
                $sslmode = 'prefer';

                if (empty($dbName) || empty($dbHost) || empty($dbUser)) {
                    $errorMessage = 'Critical database configuration (DB_HOST, DB_NAME, DB_USER) is missing from the environment.';
                    error_log($errorMessage);
                    throw new \RuntimeException($errorMessage);
                }
            }
        }

        $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName};sslmode={$sslmode}";
        
        try {
            $pdoOptions = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
            $pdo = new PDO($dsn, $dbUser, $dbPass, $pdoOptions);
            return $pdo;
        } catch (\PDOException $e) {
            $safeDsnForLog = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName};user={$dbUser}";
            error_log("Database connection failed in DI container for DSN: {$safeDsnForLog}. Error: " . $e->getMessage());
            throw $e;
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
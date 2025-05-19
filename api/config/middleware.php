<?php

require_once __DIR__ . '/../RateLimitMiddleware.php'; 

use Slim\App;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tuupola\Middleware\CorsMiddleware;
use Psr\Log\LoggerInterface;
use RateLimitMiddleware;

return function (App $app) {
    $container = $app->getContainer(); // Get the container

    // Add Slim Built-in Middleware
    $app->addBodyParsingMiddleware(); 
    $app->addRoutingMiddleware();     

    // --- Add CORS Middleware ---
    // Read allowed origins from environment variable
    $allowedOriginsEnv = $_ENV['CORS_ALLOWED_ORIGINS'] ?? ''; // Default to empty string if not set
    $allowedOrigins = [];
    if (!empty($allowedOriginsEnv)) {
        if ($allowedOriginsEnv === '*') {
            $allowedOrigins = ['*']; // Special case for all origins
        } else {
            // Split by comma if you want to allow multiple specific origins
            $allowedOrigins = array_map('trim', explode(',', $allowedOriginsEnv));
        }
    } else {
        // Default if not set - for local dev, you might allow your frontend.
        // For production, it's better to ensure this is explicitly set.
        // Consider logging a warning if it's not set in a production-like environment.
        // For now, let's default to an empty array, which might be restrictive,
        // or you could default to your known local frontend.
        // Example: $allowedOrigins = ['http://localhost:5173']; 
        // Or, to be safe if unset, make it restrictive:
        $allowedOrigins = []; // Or handle this case by logging an error/warning
        if ($container->has(LoggerInterface::class)) {
            $logger = $container->get(LoggerInterface::class);
            $logger->warning('CORS_ALLOWED_ORIGINS environment variable is not set. CORS might be restrictive.');
        } else {
            error_log('CORS_ALLOWED_ORIGINS environment variable is not set. CORS might be restrictive.');
        }
    }

    $app->add(new CorsMiddleware([
        "origin" => $allowedOrigins, 
        "methods" => ["GET", "POST", "PUT", "PATCH", "DELETE"], 
        "headers.allow" => ["Content-Type", "Authorization", "X-Requested-With"], 
        "headers.expose" => [], 
        "credentials" => false, 
        "cache" => 0, 
        "logger" => $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
    ]));

    // --- Configure and Add Rate Limiting Middleware ---
    $redisClient = null;
    if (!empty($_ENV['REDIS_URL'])) {
        try {
            $redisClient = new Predis\Client($_ENV['REDIS_URL'], ['timeout' => 0.5]);
            $redisClient->connect(); 
        } catch (Exception $e) {
            if ($container->has(LoggerInterface::class)) { // Log Redis connection failure
                $logger = $container->get(LoggerInterface::class);
                $logger->error("Failed to connect to Redis for rate limiting: " . $e->getMessage());
            } else {
                error_log("Failed to connect to Redis for rate limiting: " . $e->getMessage());
            }
            $redisClient = null; 
        }
    }
    $rateLimitMiddleware = new RateLimitMiddleware(
        (int)($_ENV['RATE_LIMIT_CUD_REQUESTS'] ?? 50),
        (int)($_ENV['RATE_LIMIT_CUD_WINDOW_SECONDS'] ?? 86400),
        $redisClient
    );
    $app->add($rateLimitMiddleware);

    // --- Error Handling Middleware ---
    $customErrorHandler = function (
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) use ($app, $container): ResponseInterface {  // <--- Pass $container
        
        $logger = null;
        if ($container->has(LoggerInterface::class)) {
            $logger = $container->get(LoggerInterface::class);
        }

        if ($logErrors && $logger) {
            $logMessage = "Error: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine();
            $context = [];
            if ($logErrorDetails) {
                $context['trace'] = $exception->getTraceAsString();
                // You can add more context from the request if needed
                // $context['request_method'] = $request->getMethod();
                // $context['request_uri'] = (string) $request->getUri();
            }
            $logger->error($logMessage, $context);
        } elseif ($logErrors) { // Fallback to error_log if logger isn't available for some reason
            error_log("Error: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
            if ($logErrorDetails) {
                error_log("Trace: " . $exception->getTraceAsString());
            }
        }

        $payload = ['error' => 'Internal Server Error'];
        if ($displayErrorDetails) {
            $payload['message'] = $exception->getMessage();
        }

        $response = $app->getResponseFactory()->createResponse();
        
        $statusCode = 500;
        if ($exception instanceof \Slim\Exception\HttpNotFoundException) {
            $statusCode = 404;
            $payload['error'] = 'Not Found';
            if ($displayErrorDetails) $payload['message'] = $exception->getMessage(); else unset($payload['message']);
        } elseif ($exception instanceof \Slim\Exception\HttpMethodNotAllowedException) {
            $statusCode = 405;
            $payload['error'] = 'Method Not Allowed';
            $payload['allowed_methods'] = $exception->getAllowedMethods();
            if ($displayErrorDetails) $payload['message'] = $exception->getMessage(); else unset($payload['message']);
        } elseif ($exception instanceof \Slim\Exception\HttpBadRequestException) {
            $statusCode = 400;
            $payload['error'] = 'Bad Request';
            if ($displayErrorDetails) $payload['message'] = $exception->getMessage(); else unset($payload['message']);
        }
        // You might want to log the specific type of HTTP error as well
        if ($logger && $statusCode >= 400 && $statusCode < 500) {
            $logger->warning("HTTP Client Error: {$payload['error']} - {$request->getMethod()} {$request->getUri()}", ['status_code' => $statusCode, 'exception_message' => $exception->getMessage()]);
        }
        
        $response->getBody()->write(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
    };

    $displayErrorDetails = ($_ENV['DEBUG_MODE'] ?? 'false') === 'true'; 
    $errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
    $errorMiddleware->setDefaultErrorHandler($customErrorHandler); 
};
<?php

use Slim\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;
use Slim\Routing\RouteCollectorProxy;
use Predis\Client as PredisClient; // For Redis
use Psr\Log\LoggerInterface; // To get logger for other parts if needed, not for RateLimitMiddleware constructor

// Directly include your RateLimitMiddleware file.
// Adjust path if RateLimitMiddleware.php is not in the parent directory of this config file (i.e., not in api/)
// This ensures the class is loaded if Composer autoloading isn't picking it up for global namespace classes.
require_once dirname(__DIR__) . '/RateLimitMiddleware.php';

return function (App $app) {
    $container = $app->getContainer(); // Get container

    // Home route - Serves the API portal page
    $app->get('/', function (ServerRequestInterface $request, ResponseInterface $response) {
        $portalPath = dirname(__DIR__) . '/portal.html'; // portal.html is in api/

        if (file_exists($portalPath)) {
            $portalContent = file_get_contents($portalPath);
            if ($portalContent === false) {
                $response->getBody()->write(json_encode(['error' => 'Could not read portal page.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }

            // Determine the base URL
            $appBaseUrl = '';
            $appEnv = strtolower($_ENV['APP_ENV'] ?? 'development');

            if ($appEnv === 'production' && isset($_ENV['PUBLIC_APP_BASE_URL']) && !empty($_ENV['PUBLIC_APP_BASE_URL'])) {
                // Use the explicitly set public base URL for production
                $appBaseUrl = $_ENV['PUBLIC_APP_BASE_URL'];
            } elseif (isset($_ENV['VERCEL_URL']) && !empty($_ENV['VERCEL_URL'])) {
                // For Vercel preview deployments or if PUBLIC_APP_BASE_URL is not set
                $appBaseUrl = 'https://' . $_ENV['VERCEL_URL'];
            } else {
                // Fallback for local development or other environments
                $uri = $request->getUri();
                $scheme = $uri->getScheme();
                $host = $uri->getHost();
                $port = $uri->getPort();
                $appBaseUrl = $scheme . '://' . $host;
                if ($port && (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443))) {
                    $appBaseUrl .= ':' . $port;
                }
            }

            // Replace placeholder
            $portalContent = str_replace('{{APP_BASE_URL}}', $appBaseUrl, $portalContent);

            $response->getBody()->write($portalContent);
            return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
        } else {
            $response->getBody()->write(json_encode(['message' => 'Welcome to CodeStack API. Portal page not found at: ' . $portalPath]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }
    });

    // --- Add these routes for local development serving of api-docs ---
    // Serve api-docs.html for local development
    $app->get('/api-docs.html', function (ServerRequestInterface $request, ResponseInterface $response) {
        // Path from codestack/api/config/ (where this file is) to codestack/public/api-docs.html
        $filePath = dirname(dirname(__DIR__)) . '/public/api-docs.html'; 
        
        if (file_exists($filePath)) {
            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) {
                $response->getBody()->write(json_encode(['error' => 'Could not read api-docs.html page.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
            $response->getBody()->write($fileContent);
            return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
        } else {
            $response->getBody()->write(json_encode(['error' => 'api-docs.html not found at: ' . $filePath]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }
    });

    // Serve openapi.yaml for local development (needed by api-docs.html)
    $app->get('/openapi.yaml', function (ServerRequestInterface $request, ResponseInterface $response) {
        // Path from codestack/api/config/ to codestack/public/openapi.yaml
        $filePath = dirname(dirname(__DIR__)) . '/public/openapi.yaml';
        
        if (file_exists($filePath)) {
            try {
                $fileStream = new Stream(fopen($filePath, 'r'));
                return $response->withBody($fileStream)
                                ->withHeader('Content-Type', 'application/vnd.oai.openapi+yaml; charset=UTF-8'); 
                                // Or 'application/x-yaml' or 'text/yaml'
            } catch (\RuntimeException $e) {
                $response->getBody()->write(json_encode(['error' => 'Could not open openapi.yaml: ' . $e->getMessage()]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
        } else {
            $response->getBody()->write(json_encode(['error' => 'openapi.yaml not found at: ' . $filePath]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }
    });
    // --- End of routes for local api-docs ---

    // Instantiate RateLimitMiddleware
    $redisClient = null;
    if (!empty($_ENV['REDIS_URL'])) {
        try {
            $redisDsn = $_ENV['REDIS_URL'];
            if (strpos($redisDsn, 'redis://') !== 0 && strpos($redisDsn, 'rediss://') !== 0) {
                $redisDsn = 'redis://' . $redisDsn;
            }
            $redisClient = new PredisClient($redisDsn, ['timeout' => 0.5]);
            $redisClient->connect();
        } catch (Exception $e) {
            // Log this error if you have a logger available via container
            if ($container->has(LoggerInterface::class)) {
                $logger = $container->get(LoggerInterface::class);
                $logger->error("Failed to connect to Redis for rate limiting in routes.php: " . $e->getMessage(), ['exception' => $e]);
            } else {
                error_log("Failed to connect to Redis for rate limiting in routes.php: " . $e->getMessage());
            }
            $redisClient = null; // Fallback
        }
    }

    $rateLimitMiddleware = new RateLimitMiddleware(
        (int)($_ENV['RATE_LIMIT_CUD_REQUESTS'] ?? 50),
        (int)($_ENV['RATE_LIMIT_CUD_WINDOW_SECONDS'] ?? 86400),
        $redisClient
    );

    // Group API routes
    $app->group('/api', function (RouteCollectorProxy $group) use ($rateLimitMiddleware) {
        // Route for the API base path /api
        $group->get('', function (ServerRequestInterface $request, ResponseInterface $response) {
            // Determine the base URL (same logic as '/' route)
            $apiBaseUrl = '';
            $appEnv = strtolower($_ENV['APP_ENV'] ?? 'development');

            if ($appEnv === 'production' && isset($_ENV['PUBLIC_APP_BASE_URL']) && !empty($_ENV['PUBLIC_APP_BASE_URL'])) {
                $apiBaseUrl = $_ENV['PUBLIC_APP_BASE_URL'];
            } elseif (isset($_ENV['VERCEL_URL']) && !empty($_ENV['VERCEL_URL'])) {
                $apiBaseUrl = 'https://' . $_ENV['VERCEL_URL'];
            } else {
                $uri = $request->getUri();
                $scheme = $uri->getScheme();
                $authority = $uri->getAuthority(); // Includes host and port if non-standard
                $apiBaseUrl = $scheme . '://' . $authority;
            }

            $payload = [
                'message' => 'Welcome to the CodeStack API base. Please use specific endpoints.',
                'available_categories' => [
                    $apiBaseUrl . '/api/snippets',
                    $apiBaseUrl . '/api/languages',
                    $apiBaseUrl . '/api/tags'
                ]
            ];
            $response->getBody()->write(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // Publicly accessible GET routes (not rate-limited by this specific middleware)
        $group->get('/snippets', \App\Actions\Snippets\ListSnippetsAction::class);
        $group->get('/snippets/random', \App\Actions\Snippets\GetRandomSnippetAction::class); // Must be before /snippets/{id}
        $group->get('/snippets/{id}', \App\Actions\Snippets\GetSnippetAction::class);
        $group->get('/languages', \App\Actions\Meta\ListLanguagesAction::class);
        $group->get('/tags', \App\Actions\Meta\ListTagsAction::class);
        $group->post('/snippets/batch-get', \App\Actions\Snippets\BatchGetSnippetsAction::class); // Not rate-limited as per PRD

        // Routes that require rate limiting
        $group->post('/snippets', \App\Actions\Snippets\CreateSnippetAction::class)
            ->add($rateLimitMiddleware);

        $group->put('/snippets/{id}', \App\Actions\Snippets\UpdateSnippetAction::class)
            ->add($rateLimitMiddleware);

        $group->delete('/snippets/{id}', \App\Actions\Snippets\DeleteSnippetAction::class)
            ->add($rateLimitMiddleware);

        $group->post('/snippets/{id}/verify-modification-code', \App\Actions\Snippets\VerifyModificationCodeAction::class)
            ->add($rateLimitMiddleware);
    });

    // Fallback for any other /api routes not matched (optional, if you have an ApiNotFoundAction)
    // $app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/api[/{params:.*}]', \App\Actions\General\ApiNotFoundAction::class);
};
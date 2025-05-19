<?php

use Slim\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

return function (App $app) {
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

    // Route for the API base path /api
    $app->get('/api', function (ServerRequestInterface $request, ResponseInterface $response) {
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
        $response->getBody()->write(json_encode($payload, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Create Snippet
    $app->post('/api/snippets', \App\Actions\Snippets\CreateSnippetAction::class);

    // Get all snippets (List Snippets)
    $app->get('/api/snippets', \App\Actions\Snippets\ListSnippetsAction::class);

    // Get a single random public snippet
    // THIS ROUTE MUST BE DEFINED BEFORE /api/snippets/{id}
    $app->get('/api/snippets/random', \App\Actions\Snippets\GetRandomSnippetAction::class);

    // Get a Single Snippet by ID
    $app->get('/api/snippets/{id}', \App\Actions\Snippets\GetSnippetAction::class);

    // Update Snippet
    $app->put('/api/snippets/{id}', \App\Actions\Snippets\UpdateSnippetAction::class);

    // Delete Snippet
    $app->delete('/api/snippets/{id}', \App\Actions\Snippets\DeleteSnippetAction::class);

    // Get unique languages
    $app->get('/api/languages', \App\Actions\Meta\ListLanguagesAction::class);

    // Get unique tags
    $app->get('/api/tags', \App\Actions\Meta\ListTagsAction::class);

    // Batch get snippets by IDs
    $app->post('/api/snippets/batch-get', \App\Actions\Snippets\BatchGetSnippetsAction::class);

    // Verify modification code for a snippet
    $app->post('/api/snippets/{id}/verify-modification-code', \App\Actions\Snippets\VerifyModificationCodeAction::class);
};
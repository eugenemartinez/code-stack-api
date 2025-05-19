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
            $uri = $request->getUri();
            $scheme = $uri->getScheme();
            $host = $uri->getHost();
            $port = $uri->getPort();
            $appBaseUrl = $scheme . '://' . $host;
            if ($port && (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443))) {
                $appBaseUrl .= ':' . $port;
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
        $payload = [
            'message' => 'Welcome to the CodeStack API base. Please use specific endpoints.',
            'available_categories' => [
                $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority() . '/api/snippets',
                $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority() . '/api/languages',
                $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority() . '/api/tags'
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
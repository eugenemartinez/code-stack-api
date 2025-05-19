<?php

namespace App\Actions\Snippets;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PDO;
use Ramsey\Uuid\Uuid;
use Psr\Log\LoggerInterface;

class GetSnippetAction
{
    private PDO $db;
    private LoggerInterface $logger;

    public function __construct(PDO $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $snippetId = $args['id'] ?? null;

        if (!Uuid::isValid((string)$snippetId)) {
            $this->logger->warning('Invalid snippet ID format for retrieval.', ['id_received' => $snippetId]);
            $errorPayload = ['error' => 'Invalid snippet ID format'];
            $response->getBody()->write(json_encode($errorPayload));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $sql = "SELECT id, title, description, username, language, tags, code, created_at, updated_at 
                    FROM code_snippets 
                    WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':id', $snippetId);
            $stmt->execute();
            
            $snippet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$snippet) {
                $this->logger->info("Snippet with ID `{$snippetId}` not found for retrieval.");
                $errorPayload = ['error' => 'Snippet not found'];
                $response->getBody()->write(json_encode($errorPayload));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            // Process tags from PostgreSQL array string to PHP array
            if (!empty($snippet['tags']) && is_string($snippet['tags'])) {
                $tagsString = trim($snippet['tags'], '{}');
                if (empty($tagsString)) {
                    $snippet['tags'] = [];
                } else {
                    // Use the more robust regex for parsing tags, consistent with other actions
                    preg_match_all('/(?<=^|,)(?:"((?:[^"\\\\]|\\\\.)*)"|([^,"]*))/', $tagsString, $matches, PREG_SET_ORDER);
                    $parsedTags = [];
                    foreach ($matches as $match) {
                        if (isset($match[2]) && $match[2] !== '') { // Unquoted tag
                            $parsedTags[] = $match[2];
                        } elseif (isset($match[1])) { // Quoted tag
                            $parsedTags[] = str_replace(['\\\\', '\\"'], ['\\', '"'], $match[1]);
                        }
                    }
                    $snippet['tags'] = $parsedTags;
                }
            } else {
                $snippet['tags'] = [];
            }
            
            // 'modification_code' is not selected, so no need to unset it.

            $this->logger->info("Snippet with ID `{$snippetId}` retrieved successfully.");
            $response->getBody()->write(json_encode($snippet));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\PDOException $e) {
            $this->logger->error("Database error in GetSnippetAction for ID {$snippetId}: " . $e->getMessage(), ['exception' => $e]);
            $errorPayload = ['error' => 'Failed to retrieve snippet due to a database issue.'];
            // Optionally include $e->getMessage() in dev mode, but not usually in prod for error details.
            // if (($_ENV['DEBUG_MODE'] ?? 'false') === 'true') {
            //     $errorPayload['details'] = $e->getMessage();
            // }
            $response->getBody()->write(json_encode($errorPayload));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
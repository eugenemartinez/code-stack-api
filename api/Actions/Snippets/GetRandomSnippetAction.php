<?php

namespace App\Actions\Snippets;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PDO;

class GetRandomSnippetAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            // For PostgreSQL, ORDER BY RANDOM() is a common way to get a random row.
            // For other databases like MySQL, it would be RAND().
            $sql = "SELECT id, title, description, username, language, tags, code, created_at, updated_at 
                    FROM code_snippets 
                    ORDER BY RANDOM() 
                    LIMIT 1";
            
            $stmt = $this->db->query($sql);
            $snippet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$snippet) {
                // This could happen if there are no snippets at all
                $errorPayload = ['error' => 'No snippets available'];
                $response->getBody()->write(json_encode($errorPayload));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            // Process tags from PostgreSQL array string to PHP array
            if (!empty($snippet['tags']) && is_string($snippet['tags'])) {
                $tagsString = trim($snippet['tags'], '{}');
                if (empty($tagsString)) {
                    $snippet['tags'] = [];
                } else {
                    // THIS IS THE ROBUST PARSING LOGIC
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
                $snippet['tags'] = []; // Ensure it's an array if tags are null or not a string
            }
            
            unset($snippet['modification_code']); // Ensure modification_code is not present

            $response->getBody()->write(json_encode($snippet));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\PDOException $e) {
            error_log("Error in GetRandomSnippetAction: " . $e->getMessage());
            $errorPayload = ['error' => 'Failed to retrieve random snippet', 'details' => $e->getMessage()];
            $response->getBody()->write(json_encode($errorPayload));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
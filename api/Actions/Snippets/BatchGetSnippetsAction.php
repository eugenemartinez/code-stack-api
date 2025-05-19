<?php

namespace App\Actions\Snippets;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PDO;
use Ramsey\Uuid\Uuid;

class BatchGetSnippetsAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $request->getParsedBody();

        if (!isset($data['ids']) || !is_array($data['ids'])) {
            $errorPayload = ['error' => 'Missing or invalid "ids" field. It should be an array of snippet IDs.'];
            $response->getBody()->write(json_encode($errorPayload));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $snippetIds = $data['ids'];
        if (empty($snippetIds)) {
            $response->getBody()->write(json_encode([])); // Return empty array if no IDs provided
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        }

        $validSnippetIds = [];
        foreach ($snippetIds as $id) {
            if (Uuid::isValid((string)$id)) {
                $validSnippetIds[] = (string)$id;
            }
        }

        if (empty($validSnippetIds)) {
            // If input IDs were provided but none were valid UUIDs
            $errorPayload = ['error' => 'No valid snippet IDs provided.'];
            $response->getBody()->write(json_encode($errorPayload));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $placeholders = implode(',', array_fill(0, count($validSnippetIds), '?'));
            
            $sql = "SELECT id, title, description, username, language, tags, code, created_at, updated_at 
                    FROM code_snippets 
                    WHERE id IN ({$placeholders})";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($validSnippetIds); // PDO can handle binding array values to IN clause with ? placeholders
            
            $snippets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $processedSnippets = array_map(function ($snippet) {
                if (!empty($snippet['tags']) && is_string($snippet['tags'])) {
                    $tagsString = trim($snippet['tags'], '{}');
                    // Handle quoted elements if your DB stores them that way for arrays
                    if (strpos($tagsString, '"') !== false) {
                         preg_match_all('/"([^"]+)"/', $tagsString, $matches);
                         $snippet['tags'] = $matches[1];
                    } else {
                         $snippet['tags'] = ($tagsString === '') ? [] : explode(',', $tagsString);
                    }
                } else {
                    $snippet['tags'] = [];
                }
                unset($snippet['modification_code']); // Ensure modification_code is not present
                return $snippet;
            }, $snippets);
            
            $response->getBody()->write(json_encode($processedSnippets));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\PDOException $e) {
            error_log("Error in BatchGetSnippetsAction: " . $e->getMessage());
            $errorPayload = ['error' => 'Failed to retrieve snippets by batch', 'details' => $e->getMessage()];
            $response->getBody()->write(json_encode($errorPayload));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
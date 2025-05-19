<?php

namespace App\Actions\Meta; // New namespace for meta-related actions

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PDO;

class ListLanguagesAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $sql = "SELECT DISTINCT language 
                    FROM code_snippets 
                    WHERE language IS NOT NULL AND language <> '' 
                    ORDER BY language ASC";
            $stmt = $this->db->query($sql);
            $languages = $stmt->fetchAll(PDO::FETCH_COLUMN, 0); // Fetch as a flat array of languages

            $response->getBody()->write(json_encode($languages));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\PDOException $e) {
            error_log("Error in ListLanguagesAction: " . $e->getMessage());
            $errorPayload = ['error' => 'Failed to retrieve languages', 'details' => $e->getMessage()];
            $response->getBody()->write(json_encode($errorPayload));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
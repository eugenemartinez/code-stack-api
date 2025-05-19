<?php

namespace App\Actions\Snippets;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PDO;
use Ramsey\Uuid\Uuid;
use Respect\Validation\Validator as v; // Added
use Respect\Validation\Exceptions\NestedValidationException; // Added

class VerifyModificationCodeAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $snippetId = $args['id'];

        if (!Uuid::isValid($snippetId)) {
            $response->getBody()->write(json_encode(['verified' => false, 'reason' => 'Invalid snippet ID format']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        }

        $data = $request->getParsedBody();

        // Validate modification_code presence and format
        $modificationCodeValidator = v::key('modification_code', v::stringType()->alnum()->length(12, 12), true); // true makes the key mandatory

        try {
            $modificationCodeValidator->assert($data);
        } catch (NestedValidationException $e) {
            // If modification_code is missing or invalid format
            $response->getBody()->write(json_encode(['verified' => false, 'reason' => 'Invalid or missing modification_code format. Must be 12 alphanumeric characters.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        }
        
        $modificationCode = (string)$data['modification_code'];

        try {
            $sql = "SELECT modification_code FROM code_snippets WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':id', $snippetId);
            $stmt->execute();
            
            $snippet = $stmt->fetch(PDO::FETCH_ASSOC);

            $verified = false;
            $payload = ['verified' => false]; // Default payload

            if ($snippet) {
                if ($snippet['modification_code'] === $modificationCode) {
                    $verified = true;
                    $payload['verified'] = true;
                }
                // If snippet found but code doesn't match, 'verified' remains false, no specific 'reason' for mismatch by default
            }
            // If snippet is not found, 'verified' remains false, no specific 'reason' for not found by default

            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\PDOException $e) {
            error_log("Error in VerifyModificationCodeAction: " . $e->getMessage());
            $response->getBody()->write(json_encode(['verified' => false, 'reason' => 'Database error']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
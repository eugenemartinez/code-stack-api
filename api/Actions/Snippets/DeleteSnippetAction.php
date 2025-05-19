<?php

namespace App\Actions\Snippets;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PDO;
use Ramsey\Uuid\Uuid;
use Respect\Validation\Validator as v;
use Psr\Log\LoggerInterface; // Added

class DeleteSnippetAction
{
    private PDO $db;
    private LoggerInterface $logger; // Added

    // Updated constructor
    public function __construct(PDO $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $snippetId = $args['id'] ?? null; // Ensure $snippetId is always defined

        // --- 1. Validate Snippet ID (Path Parameter) ---
        if (!Uuid::isValid((string)$snippetId)) { // Cast to string for Uuid::isValid
            $this->logger->warning('Invalid snippet ID format for deletion.', ['id_received' => $snippetId]);
            $response->getBody()->write(json_encode(['error' => 'Invalid snippet ID format.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $parsedBody = $request->getParsedBody();

        // --- 2. Define and Perform Validation for modification_code ---
        $validationRules = [
            'modification_code' => v::notEmpty()
                                    ->stringType()
                                    ->alnum() // Expecting alphanumeric
                                    ->length(12, 12) // Expecting exactly 12 characters
                                    ->setName('Modification Code'),
        ];

        $validationErrors = [];
        $valueToValidate = $parsedBody['modification_code'] ?? null;

        try {
            $validationRules['modification_code']->assert($valueToValidate);
        } catch (\Respect\Validation\Exceptions\NestedValidationException $exception) {
            $validationErrors['modification_code'] = $exception->getMessages();
        }

        if (!empty($validationErrors)) {
            $this->logger->warning(
                "Snippet deletion validation failed for ID: {$snippetId}", 
                ['errors' => $validationErrors, 'payload' => ['modification_code' => $valueToValidate]]
            );
            $response->getBody()->write(json_encode(['errors' => $validationErrors]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // Bad Request for validation issues
        }
        
        $modificationCode = $valueToValidate; // Use the validated value

        // --- 3. Proceed with Deletion Logic ---
        try {
            // First, verify the snippet exists and the modification_code is correct
            $sqlVerify = "SELECT modification_code FROM code_snippets WHERE id = :id";
            $stmtVerify = $this->db->prepare($sqlVerify);
            $stmtVerify->bindParam(':id', $snippetId);
            $stmtVerify->execute();
            $existingSnippet = $stmtVerify->fetch(PDO::FETCH_ASSOC);

            if (!$existingSnippet) {
                $this->logger->info("Attempt to delete non-existent snippet.", ['id' => $snippetId]);
                $response->getBody()->write(json_encode(['error' => 'Snippet not found.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            if ($existingSnippet['modification_code'] !== $modificationCode) {
                $this->logger->warning(
                    "Invalid modification code for snippet deletion.", 
                    ['id' => $snippetId, 'provided_code' => $modificationCode]
                );
                $response->getBody()->write(json_encode(['error' => 'Invalid modification code.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403); // Forbidden
            }

            // Delete the snippet
            $sqlDelete = "DELETE FROM code_snippets WHERE id = :id AND modification_code = :modification_code"; // Added modification_code to WHERE for safety
            $stmtDelete = $this->db->prepare($sqlDelete);
            $stmtDelete->bindParam(':id', $snippetId);
            $stmtDelete->bindParam(':modification_code', $modificationCode); // Bind it
            $stmtDelete->execute();

            if ($stmtDelete->rowCount() > 0) {
                $this->logger->info("Snippet with ID `{$snippetId}` was deleted successfully.");
                return $response->withStatus(204); // No Content
            } else {
                // This case implies the snippet was found by the SELECT, but the DELETE failed.
                // This could happen if the modification_code check above was somehow bypassed or
                // if the record was deleted between the SELECT and DELETE (race condition).
                // Or if the modification_code in the DB was different than what was fetched (highly unlikely).
                $this->logger->error(
                    "Failed to delete snippet after verification, or snippet already deleted.", 
                    ['id' => $snippetId, 'modification_code' => $modificationCode]
                );
                $response->getBody()->write(json_encode(['error' => 'Failed to delete snippet or snippet was already deleted.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404); // Or 500 if truly unexpected
            }

        } catch (\PDOException $e) {
            $this->logger->error("Database error deleting snippet {$snippetId}: " . $e->getMessage(), ['exception' => $e]);
            $response->getBody()->write(json_encode(['error' => 'Failed to delete snippet due to a database issue.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
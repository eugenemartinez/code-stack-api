<?php

namespace App\Actions\Snippets;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PDO;
use Ramsey\Uuid\Uuid;
use Respect\Validation\Validator as v; // For input validation
use HTMLPurifier;                     // For HTML sanitization
use Psr\Log\LoggerInterface;          // For logging

class UpdateSnippetAction
{
    private PDO $db;
    private HTMLPurifier $htmlPurifier;
    private LoggerInterface $logger;

    public function __construct(PDO $db, HTMLPurifier $htmlPurifier, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->htmlPurifier = $htmlPurifier;
        $this->logger = $logger;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $snippetId = $args['id'];

        if (!Uuid::isValid($snippetId)) {
            $this->logger->warning('Update attempt with invalid snippet ID format.', ['id' => $snippetId]);
            $response->getBody()->write(json_encode(['error' => 'Invalid snippet ID format']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $parsedBody = $request->getParsedBody();

        // --- DIRECT TITLE VALIDATION TEST ---
        if (isset($parsedBody['title'])) {
            $titleToTest = $parsedBody['title'];
            $this->logger->debug("DIRECT TEST: Title value", ['title' => $titleToTest, 'length_php' => strlen($titleToTest)]);
            try {
                v::length(1, 255)->assert($titleToTest);
                $this->logger->debug("DIRECT TEST: Title length validation PASSED (unexpected for long title)");
            } catch (\Respect\Validation\Exceptions\ValidationException $e) {
                // Use getMessage() for ValidationException and its direct children
                $errorMessages = [$e->getMessage()]; // Put the single message into an array for consistency if needed
                // You can also get specific template messages if you know the rule names
                // For instance, $e->findMessages(['length']) might work if the exception has structured messages.
                // But for a simple log, getMessage() is fine.
                $this->logger->debug("DIRECT TEST: Title length validation FAILED AS EXPECTED", ['main_message' => $e->getMessage(), 'details' => $errorMessages]);
                // For this direct test, let's immediately return a 400 if it fails here
                $response->getBody()->write(json_encode(['error' => 'DIRECT TEST: Title too long', 'details' => $errorMessages]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
        }
        // --- END DIRECT TITLE VALIDATION TEST ---


        // --- 1. Define Validation Rules ---
        // For PUT, fields are often optional if not changing, but if provided, they must be valid.
        // modification_code is mandatory.
        // The array_key_exists check in the validation loop handles the "optional presence" of fields.
        // If a field is present, these rules define its validity.
        $validationRules = [
            'title' => v::notEmpty()->length(1, 255)->setName('Title'), // If present, must not be empty
            'description' => v::optional(v::stringType()->length(null, 1000))->setName('Description'), // If present, empty string is allowed by length(null,X)
            'code' => v::notEmpty()->stringType()->setName('Code'), // If present, must not be empty
            'language' => v::notEmpty()->stringType()->length(1, 50)->setName('Language'), // If present, must not be empty
            'tags' => v::optional(v::arrayType()->each(v::stringType()->length(1, 50)))->setName('Tags'), // If present, empty array is allowed
            'modification_code' => v::notEmpty()->stringType()->length(12, 12)->setName('Modification Code'), // Mandatory
        ];

        // --- 2. Validate Data ---
        $validationErrors = [];
        foreach ($validationRules as $field => $rule) {
            try {
                $valueToValidate = $parsedBody[$field] ?? null;

                // If a field is not present in the payload, Respect\Validation\Optional will handle it.
                // We only assert if the key exists or if it's a mandatory field like modification_code.
                if (array_key_exists($field, $parsedBody) || $field === 'modification_code') {
                    // ---- TEMPORARY DEBUGGING ----
                    if ($field === 'title') {
                        $this->logger->debug("Validating title", ['value' => $valueToValidate, 'length' => strlen($valueToValidate ?? '')]);
                    }
                    // ---- END TEMPORARY DEBUGGING ----
                    $rule->assert($valueToValidate);
                }
            } catch (\Respect\Validation\Exceptions\ValidationException $exception) { // Broaden to ValidationException
                // ---- TEMPORARY DEBUGGING ----
                if ($field === 'title') {
                    $errorMessagesForDebug = [];
                    if ($exception instanceof \Respect\Validation\Exceptions\NestedValidationException) {
                        $errorMessagesForDebug = $exception->getMessages();
                    } else {
                        $errorMessagesForDebug = [$exception->getMessage()];
                    }
                    $this->logger->debug("Validation FAILED for title", ['messages' => $errorMessagesForDebug]);
                }
                // ---- END TEMPORARY DEBUGGING ----
                // Ensure messages are consistently an array
                if ($exception instanceof \Respect\Validation\Exceptions\NestedValidationException) {
                    $validationErrors[$field] = $exception->getMessages();
                } else {
                    // For single exceptions, getMessage() returns the main message.
                    // We store it as an array for consistency.
                    $validationErrors[$field] = [$exception->getMessage()];
                }
            }
        }

        // Refine specific error messages if necessary
        if (isset($validationErrors['tags']['Tags']) && $validationErrors['tags']['Tags'] === false) {
            $validationErrors['tags']['Tags'] = 'Tags must be of type array';
        }

        if (!empty($validationErrors)) {
            $this->logger->warning('Snippet update validation failed.', ['id' => $snippetId, 'errors' => $validationErrors, 'payload' => $parsedBody]);
            $response->getBody()->write(json_encode(['errors' => $validationErrors]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
        
        // --- Modification Code Verification (already good, just ensure $parsedBody['modification_code'] is used) ---
        try {
            $sqlVerify = "SELECT modification_code FROM code_snippets WHERE id = :id";
            $stmtVerify = $this->db->prepare($sqlVerify);
            $stmtVerify->bindParam(':id', $snippetId);
            $stmtVerify->execute();
            $existingSnippet = $stmtVerify->fetch(PDO::FETCH_ASSOC);

            if (!$existingSnippet) {
                $this->logger->info('Snippet not found for update.', ['id' => $snippetId]);
                $response->getBody()->write(json_encode(['error' => 'Snippet not found']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            if ($existingSnippet['modification_code'] !== $parsedBody['modification_code']) {
                $this->logger->warning('Invalid modification_code for update.', ['id' => $snippetId, 'provided_code' => $parsedBody['modification_code']]);
                $response->getBody()->write(json_encode(['error' => 'Invalid modification_code']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }

            // --- 3. Sanitize Data & Build Update Query ---
            $fieldsToUpdate = [];
            $updateValues = [':id' => $snippetId];
            $sanitizedDataForUpdate = [];

            if (array_key_exists('title', $parsedBody)) {
                $sanitizedTitle = strip_tags(trim($parsedBody['title']));
                if ($sanitizedTitle !== '') { // Ensure title is not empty after sanitization if provided
                    $fieldsToUpdate[] = 'title = :title';
                    $updateValues[':title'] = $sanitizedTitle;
                    $sanitizedDataForUpdate['title'] = $sanitizedTitle;
                } else if (isset($parsedBody['title'])) { // If title was provided but became empty after sanitization
                     $validationErrors['title'] = ['Title cannot be empty.'];
                }
            }
            if (array_key_exists('description', $parsedBody)) {
                $sanitizedDescription = is_null($parsedBody['description']) ? null : strip_tags(trim($parsedBody['description']));
                $fieldsToUpdate[] = 'description = :description';
                $updateValues[':description'] = $sanitizedDescription;
                $sanitizedDataForUpdate['description'] = $sanitizedDescription;
            }
            if (array_key_exists('code', $parsedBody)) {
                $sanitizedCode = $this->htmlPurifier->purify($parsedBody['code']);
                 if ($sanitizedCode !== '') { // Ensure code is not empty after sanitization if provided
                    $fieldsToUpdate[] = 'code = :code';
                    $updateValues[':code'] = $sanitizedCode;
                    $sanitizedDataForUpdate['code'] = $sanitizedCode;
                } else if (isset($parsedBody['code'])) {
                    $validationErrors['code'] = ['Code cannot be empty.'];
                }
            }
            if (array_key_exists('language', $parsedBody)) {
                $sanitizedLanguage = strip_tags(trim($parsedBody['language']));
                if ($sanitizedLanguage !== '') { // Ensure language is not empty after sanitization if provided
                    $fieldsToUpdate[] = 'language = :language';
                    $updateValues[':language'] = $sanitizedLanguage;
                    $sanitizedDataForUpdate['language'] = $sanitizedLanguage;
                } else if (isset($parsedBody['language'])) {
                    $validationErrors['language'] = ['Language cannot be empty.'];
                }
            }
            if (array_key_exists('tags', $parsedBody)) {
                $sanitizedTags = [];
                if (is_array($parsedBody['tags'])) {
                    foreach ($parsedBody['tags'] as $tag) {
                        if (is_string($tag)) {
                            $cleanTag = strip_tags(trim($tag));
                            if (!empty($cleanTag)) {
                                $sanitizedTags[] = $cleanTag;
                            }
                        }
                    }
                }
                $fieldsToUpdate[] = 'tags = :tags';
                // Prepare tags for PostgreSQL array literal string
                $tagsJson = '{}';
                if (!empty($sanitizedTags)) {
                    $escapedTags = array_map(function($tag) {
                        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $tag) . '"';
                    }, $sanitizedTags);
                    $tagsJson = '{' . implode(',', $escapedTags) . '}';
                }
                $updateValues[':tags'] = $tagsJson;
                $sanitizedDataForUpdate['tags'] = $sanitizedTags; // Store the PHP array for logging/response
            }
            
            // Re-check validation errors that might have occurred during sanitization (e.g. title becoming empty)
            if (!empty($validationErrors)) {
                $this->logger->warning('Snippet update validation failed after sanitization.', ['id' => $snippetId, 'errors' => $validationErrors, 'payload' => $parsedBody]);
                $response->getBody()->write(json_encode(['errors' => $validationErrors]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            if (empty($fieldsToUpdate)) {
                $this->logger->info('No updatable fields provided for snippet.', ['id' => $snippetId, 'payload' => $parsedBody]);
                // Optionally, return the current snippet or a specific message
                // For now, let's consider it a bad request if nothing is to be updated.
                $response->getBody()->write(json_encode(['error' => 'No updatable fields provided or fields became empty after sanitization.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $fieldsToUpdate[] = 'updated_at = :updated_at';
            $updateValues[':updated_at'] = gmdate('Y-m-d H:i:s');

            $sqlSetClause = implode(', ', $fieldsToUpdate);
            $sqlUpdate = "UPDATE code_snippets SET {$sqlSetClause} WHERE id = :id";
            
            $stmtUpdate = $this->db->prepare($sqlUpdate);
            $updateSuccessful = $stmtUpdate->execute($updateValues);

            if (!$updateSuccessful) {
                // Log critical error and return 500
                $errorInfo = $stmtUpdate->errorInfo();
                $this->logger->critical(
                    'Failed to execute snippet update.',
                    [
                        'id' => $snippetId,
                        'query_params' => $updateValues, // Log the parameters that were attempted
                        'error' => $errorInfo // Log PDO error information
                    ]
                );
                $response->getBody()->write(json_encode(['error' => 'Internal Server Error: Could not update snippet.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }

            // Fetch the updated snippet to return
            $sqlFetch = "SELECT id, title, description, username, language, tags, code, created_at, updated_at FROM code_snippets WHERE id = :id";
            $stmtFetch = $this->db->prepare($sqlFetch);
            $stmtFetch->bindParam(':id', $snippetId);
            $stmtFetch->execute();
            $updatedSnippet = $stmtFetch->fetch(PDO::FETCH_ASSOC);

            if ($updatedSnippet) {
                // Process tags for response
                if (!empty($updatedSnippet['tags']) && is_string($updatedSnippet['tags'])) {
                    $tagsString = trim($updatedSnippet['tags'], '{}');
                     if (empty($tagsString)) {
                        $updatedSnippet['tags'] = [];
                    } else {
                        preg_match_all('/(?<=^|,)(?:"((?:[^"\\\\]|\\\\.)*)"|([^,"]*))/', $tagsString, $matches, PREG_SET_ORDER);
                        $parsedTags = [];
                        foreach ($matches as $match) {
                            if (isset($match[2]) && $match[2] !== '') { $parsedTags[] = $match[2]; } 
                            elseif (isset($match[1])) { $parsedTags[] = str_replace(['\\\\', '\\"'], ['\\', '"'], $match[1]); }
                        }
                        $updatedSnippet['tags'] = $parsedTags;
                    }
                } else {
                    $updatedSnippet['tags'] = [];
                }
                $this->logger->info("Snippet `{$snippetId}` was updated.", ['id' => $snippetId, 'updated_fields' => array_keys($sanitizedDataForUpdate)]);
                $response->getBody()->write(json_encode($updatedSnippet));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
            } else {
                $this->logger->error('Failed to retrieve updated snippet after update.', ['id' => $snippetId]);
                $response->getBody()->write(json_encode(['error' => 'Failed to retrieve updated snippet after update']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }

        } catch (\PDOException $e) {
            $this->logger->error("Database error updating snippet `{$snippetId}`: " . $e->getMessage(), ['id' => $snippetId, 'exception' => $e]);
            $response->getBody()->write(json_encode(['error' => 'Failed to update snippet due to a database issue.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
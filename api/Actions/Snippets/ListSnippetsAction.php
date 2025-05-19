<?php

namespace App\Actions\Snippets;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PDO;
use Psr\Log\LoggerInterface; // Add this

class ListSnippetsAction
{
    private PDO $db;
    private LoggerInterface $logger; // Add this

    // Update the constructor
    public function __construct(PDO $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger; // Add this
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $queryParams = $request->getQueryParams();
            
            // Search term
            $searchTerm = $queryParams['search'] ?? null;

            // Pagination parameters
            $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
            $perPage = isset($queryParams['per_page']) ? (int)$queryParams['per_page'] : 15;

            // Filter parameters
            $filterLanguage = $queryParams['language'] ?? null;
            $filterTag = $queryParams['tag'] ?? null;

            // Sorting parameters
            $sortBy = $queryParams['sort_by'] ?? 'created_at';
            $sortOrder = isset($queryParams['order']) && strtolower($queryParams['order']) === 'asc' ? 'ASC' : 'DESC';

            $allowedSortFields = ['created_at', 'updated_at', 'title', 'language', 'username'];
            if (!in_array(strtolower($sortBy), $allowedSortFields)) {
                $sortBy = 'created_at'; // Default sort field if invalid
            }

            if ($page < 1) $page = 1;
            if ($perPage < 1) $perPage = 1;
            if ($perPage > 100) $perPage = 100; // Max perPage

            $offset = ($page - 1) * $perPage;

            // Base SQL queries
            $baseSql = "FROM code_snippets";
            $selectSql = "SELECT id, title, description, username, language, tags, created_at, updated_at "; // Exclude 'code' by default for list view for brevity
            // If you need 'code' in the list view, add it back:
            // $selectSql = "SELECT id, title, description, username, language, tags, code, created_at, updated_at ";
            
            $countSql = "SELECT COUNT(*) " . $baseSql;
            $dataSql = $selectSql . $baseSql;

            $bindings = [];
            $whereClauses = [];

            if ($searchTerm !== null && trim($searchTerm) !== '') {
                $searchTermLike = '%' . trim($searchTerm) . '%';
                // Adjust ILIKE columns as needed. Searching in 'code' can be slow.
                $whereClauses[] = "(title ILIKE :search_term OR description ILIKE :search_term OR username ILIKE :search_term)";
                $bindings[':search_term'] = $searchTermLike;
            }

            if ($filterLanguage !== null && trim($filterLanguage) !== '') {
                $whereClauses[] = "language = :language";
                $bindings[':language'] = trim($filterLanguage);
            }

            if ($filterTag !== null && trim($filterTag) !== '') {
                // This assumes tags are stored in a way that ILIKE can search effectively (e.g., as a comma-separated string or text array)
                // For PostgreSQL array: "tags @> ARRAY[:tag]" or "tags::text ILIKE :tag_like"
                $whereClauses[] = "tags::text ILIKE :tag_like"; // Example for PostgreSQL text array
                $bindings[':tag_like'] = '%' . trim($filterTag) . '%';
            }
            
            if (!empty($whereClauses)) {
                $whereSql = " WHERE " . implode(" AND ", $whereClauses);
                $countSql .= $whereSql;
                $dataSql .= $whereSql;
            }

            // Get total count
            $stmtCount = $this->db->prepare($countSql);
            $stmtCount->execute($bindings); // Use the same bindings for count if WHERE clauses are identical
            $totalSnippets = (int)$stmtCount->fetchColumn();
            $totalPages = $perPage > 0 ? ceil($totalSnippets / $perPage) : 0;

            // Add sorting and pagination to data query
            // $dataSql .= " ORDER BY " . pg_escape_identifier($sortBy) . " " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC'); // Sanitize sort field
            $dataSql .= " ORDER BY " . $sortBy . " " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC'); // Use the whitelisted $sortBy directly
            $dataSql .= " LIMIT :limit OFFSET :offset";
            
            $stmt = $this->db->prepare($dataSql);
            
            // Bind all parameters for the data query
            foreach ($bindings as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $snippets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $processedSnippets = array_map(function ($snippet) {
                if (!empty($snippet['tags']) && is_string($snippet['tags'])) {
                    // This parsing logic should match how your tags are stored and how GetAllSnippetsAction parses them
                    $tagsString = trim($snippet['tags'], '{}');
                    if (empty($tagsString)) {
                        $snippet['tags'] = [];
                    } else {
                        preg_match_all('/(?<=^|,)(?:"((?:[^"\\\\]|\\\\.)*)"|([^,"]*))/', $tagsString, $matches, PREG_SET_ORDER);
                        $parsedTags = [];
                        foreach ($matches as $match) {
                            if (isset($match[2]) && $match[2] !== '') {
                                $parsedTags[] = $match[2];
                            } elseif (isset($match[1])) {
                                $parsedTags[] = str_replace(['\\\\', '\\"'], ['\\', '"'], $match[1]);
                            }
                        }
                        $snippet['tags'] = $parsedTags;
                    }
                } else {
                    $snippet['tags'] = [];
                }
                // unset($snippet['modification_code']); // modification_code is not selected in this query
                return $snippet;
            }, $snippets);

            $paginationData = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int)$totalPages,
                'total_items' => $totalSnippets,
                'items_on_page' => count($processedSnippets)
            ];

            $responseData = [
                'data' => $processedSnippets,
                'pagination' => $paginationData
            ];
            
            $this->logger->info("Retrieved snippets list.", [
                'page' => $page, 
                'per_page' => $perPage, 
                'total_found' => $totalSnippets,
                'search_term' => $searchTerm,
                'language_filter' => $filterLanguage,
                'tag_filter' => $filterTag
            ]);
            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\PDOException $e) {
            $this->logger->error("Database error in ListSnippetsAction: " . $e->getMessage(), ['exception' => $e]);
            $errorPayload = ['error' => 'Failed to retrieve snippets due to a database issue.'];
            $response->getBody()->write(json_encode($errorPayload));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        } catch (\Exception $e) { // Catch any other general exceptions
            $this->logger->error("General error in ListSnippetsAction: " . $e->getMessage(), ['exception' => $e]);
            $errorPayload = ['error' => 'An unexpected error occurred while retrieving snippets.'];
            $response->getBody()->write(json_encode($errorPayload));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
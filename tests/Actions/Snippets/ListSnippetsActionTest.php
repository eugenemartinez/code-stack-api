<?php

declare(strict_types=1);

namespace App\Tests\Actions\Snippets;

use App\Actions\Snippets\ListSnippetsAction;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use PDO;
use PDOStatement;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid; // For generating test UUIDs if needed

class ListSnippetsActionTest extends TestCase
{
    protected $pdoMock;
    protected $stmtMock; // General statement mock
    protected $stmtCountMock; // Specific for count query
    protected $stmtDataMock;  // Specific for data query
    protected $loggerMock;
    protected $requestMock;
    protected $responseMock;
    protected $streamMock;

    protected function setUp(): void
    {
        $this->pdoMock = $this->createMock(PDO::class);
        $this->stmtMock = $this->createMock(PDOStatement::class); // Fallback if needed, but prefer specific ones
        $this->stmtCountMock = $this->createMock(PDOStatement::class);
        $this->stmtDataMock = $this->createMock(PDOStatement::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->requestMock = $this->createMock(ServerRequestInterface::class);
        $this->responseMock = $this->createMock(ResponseInterface::class);
        $this->streamMock = $this->createMock(StreamInterface::class);

        $this->responseMock->method('getBody')->willReturn($this->streamMock);
        $this->responseMock->method('withHeader')->willReturn($this->responseMock);
        $this->responseMock->method('withStatus')->willReturn($this->responseMock);
    }

    // Test methods will be added here
    public function testSuccessfulListRetrievalWithDefaults()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([]); // No query params, use defaults

        // --- Mock Database Interactions ---
        // 1. Count Query & 2. Data Query
        $this->pdoMock->expects($this->exactly(2)) // Expect exactly two calls: one for count, one for data
            ->method('prepare')
            ->with($this->logicalOr(
                $this->stringContains('SELECT COUNT(*) FROM code_snippets'), // For the count query
                $this->logicalAnd( // For the data query (more robust)
                    $this->stringContains('SELECT id, title, description, username, language, tags, created_at, updated_at FROM code_snippets'),
                    $this->stringContains('ORDER BY created_at DESC'), // Default sort
                    $this->stringContains('LIMIT :limit OFFSET :offset')
                )
            ))
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'COUNT(*)') !== false) {
                    return $this->stmtCountMock;
                }
                // If it's not the count query, assume it's the data query
                return $this->stmtDataMock;
            });

        $this->stmtCountMock->expects($this->once())
            ->method('execute')
            ->with([]) // No bindings for default count
            ->willReturn(true);
        $this->stmtCountMock->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(2); // Simulate 2 total snippets

        // 2. Data Query
        $sampleSnippet1Id = Uuid::uuid4()->toString();
        $sampleSnippet2Id = Uuid::uuid4()->toString();
        $dbSnippets = [
            [
                'id' => $sampleSnippet1Id, 'title' => 'Snippet A', 'description' => 'Desc A', 'username' => 'UserA',
                'language' => 'php', 'tags' => '{"tag1","tag2"}', 'created_at' => '2024-03-10 10:00:00', 'updated_at' => '2024-03-10 10:00:00'
            ],
            [
                'id' => $sampleSnippet2Id, 'title' => 'Snippet B', 'description' => 'Desc B', 'username' => 'UserB',
                'language' => 'python', 'tags' => '{"tag3"}', 'created_at' => '2024-03-09 10:00:00', 'updated_at' => '2024-03-09 10:00:00'
            ],
        ];

        // Bindings for data query (defaults: page 1, perPage 15)
        // For multiple calls to the same method with different arguments:
        $this->stmtDataMock->expects($this->exactly(2))
            ->method('bindValue')
            ->willReturnMap([
                [':limit', 15, PDO::PARAM_INT, true],
                [':offset', 0, PDO::PARAM_INT, true],
            ]);
        $this->stmtDataMock->expects($this->once())
            ->method('execute')
            ->with() // Changed from with([]) to with() for no arguments
            ->willReturn(true);
        $this->stmtDataMock->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbSnippets);

        // --- Expected Processed Snippets ---
        $processedSnippets = [
            [
                'id' => $sampleSnippet1Id, 'title' => 'Snippet A', 'description' => 'Desc A', 'username' => 'UserA',
                'language' => 'php', 'tags' => ['tag1', 'tag2'], 'created_at' => '2024-03-10 10:00:00', 'updated_at' => '2024-03-10 10:00:00'
            ],
            [
                'id' => $sampleSnippet2Id, 'title' => 'Snippet B', 'description' => 'Desc B', 'username' => 'UserB',
                'language' => 'python', 'tags' => ['tag3'], 'created_at' => '2024-03-09 10:00:00', 'updated_at' => '2024-03-09 10:00:00'
            ],
        ];

        // --- Expected Pagination ---
        $expectedPagination = [
            'current_page' => 1,
            'per_page' => 15,
            'total_pages' => 1, // ceil(2 / 15)
            'total_items' => 2,
            'items_on_page' => 2
        ];

        // --- Expected Response Data ---
        $expectedResponseData = [
            'data' => $processedSnippets,
            'pagination' => $expectedPagination
        ];

        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Retrieved snippets list.',
                [
                    'page' => 1, 'per_page' => 15, 'total_found' => 2,
                    'search_term' => null, 'language_filter' => null, 'tag_filter' => null
                ]
            );

        // --- Mock Response ---
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData));
        // withHeader and withStatus are already configured in setUp to return $this->responseMock

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testListRetrievalWithSpecificPageAndPerPage()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        $requestedPage = 2;
        $requestedPerPage = 5;
        $expectedOffset = ($requestedPage - 1) * $requestedPerPage; // 5

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['page' => (string)$requestedPage, 'per_page' => (string)$requestedPerPage]);

        // --- Mock Database Interactions ---
        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalOr(
                $this->stringContains('SELECT COUNT(*) FROM code_snippets'), // For the count query (no WHERE clause)
                $this->logicalAnd( // For the data query (no WHERE clause)
                    $this->stringContains('SELECT id, title, description, username, language, tags, created_at, updated_at FROM code_snippets'),
                    $this->stringContains('ORDER BY created_at DESC'), // Default sort
                    $this->stringContains('LIMIT :limit OFFSET :offset')
                )
            ))
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'COUNT(*)') !== false) {
                    return $this->stmtCountMock;
                }
                return $this->stmtDataMock;
            });

        // Count Query Mock
        $this->stmtCountMock->expects($this->once())
            ->method('execute')
            ->with([]) // Action calls $stmtCount->execute($bindings) which is [] here
            ->willReturn(true);
        $this->stmtCountMock->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(20); // Simulate 20 total snippets

        // Data Query Mock
        // Sample data generation (ensure it's sufficient and matches expected structure)
        $dbSnippetsPage2 = [];
        for ($i = 0; $i < $requestedPerPage; $i++) {
            $dbSnippetsPage2[] = [
                'id' => Uuid::uuid4()->toString(), 
                'title' => 'Snippet ' . chr(70 + $i), 
                'description' => 'Desc ' . chr(70 + $i), 
                'username' => 'User' . chr(70 + $i),
                'language' => 'php', 
                'tags' => '{"sample"}', // Raw string from DB
                'created_at' => '2024-03-10 10:00:00', 
                'updated_at' => '2024-03-10 10:00:00'
            ];
        }

        $this->stmtDataMock->expects($this->exactly(2))
            ->method('bindValue')
            ->willReturnMap([
                [':limit', $requestedPerPage, PDO::PARAM_INT, true],
                [':offset', $expectedOffset, PDO::PARAM_INT, true],
            ]);
        $this->stmtDataMock->expects($this->once())
            ->method('execute')
            ->with() // Action calls $stmt->execute() with no arguments after bindValue
            ->willReturn(true);
        $this->stmtDataMock->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbSnippetsPage2); // Return the 5 sample snippets for this page

        // --- Expected Processed Snippets ---
        $processedSnippets = array_map(function ($s) {
            $s['tags'] = ['sample']; // Parsed tags
            // Ensure all fields expected by the client are present
            unset($s['non_existent_field_if_any']); // Example: clean up if necessary
            return $s;
        }, $dbSnippetsPage2);


        // --- Expected Pagination ---
        $expectedPagination = [
            'current_page' => $requestedPage,
            'per_page' => $requestedPerPage,
            'total_pages' => (int)ceil(20 / $requestedPerPage), // (int)ceil(20 / 5) = 4
            'total_items' => 20,
            'items_on_page' => count($processedSnippets) // Should be 5
        ];

        // --- Expected Response Data ---
        $expectedResponseData = [
            'data' => $processedSnippets,
            'pagination' => $expectedPagination
        ];

        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Retrieved snippets list.',
                [
                    'page' => $requestedPage, 'per_page' => $requestedPerPage, 'total_found' => 20,
                    'search_term' => null, 'language_filter' => null, 'tag_filter' => null
                ]
            );

        // --- Mock Response ---
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData));

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testListRetrievalWithSearchTerm()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        $searchTerm = 'unique search';
        $searchTermLike = '%' . $searchTerm . '%';
        $requestedPage = 1; // Test with default page
        $requestedPerPage = 15; // Test with default per_page
        $expectedOffset = 0;

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['search' => $searchTerm]);

        // --- Mock Database Interactions ---
        $expectedWhereClause = "WHERE (title ILIKE :search_term OR description ILIKE :search_term OR username ILIKE :search_term)";

        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalOr(
                $this->logicalAnd( // Count query with WHERE
                    $this->stringContains('SELECT COUNT(*) FROM code_snippets'),
                    $this->stringContains($expectedWhereClause)
                ),
                $this->logicalAnd( // Data query with WHERE
                    $this->stringContains('SELECT id, title, description, username, language, tags, created_at, updated_at FROM code_snippets'),
                    $this->stringContains($expectedWhereClause),
                    $this->stringContains('ORDER BY created_at DESC'),
                    $this->stringContains('LIMIT :limit OFFSET :offset')
                )
            ))
            ->willReturnCallback(function ($sql) use ($expectedWhereClause) {
                // Check if the SQL contains the WHERE clause for search term
                if (strpos($sql, $expectedWhereClause) === false) {
                    // This might happen if the logic for adding WHERE is flawed
                    // Forcing a failure or returning a specific mock can help debug
                    // For now, rely on the with() clause above.
                }
                if (strpos($sql, 'COUNT(*)') !== false) {
                    return $this->stmtCountMock;
                }
                return $this->stmtDataMock;
            });

        // Count Query Mock
        $this->stmtCountMock->expects($this->once())
            ->method('execute')
            ->with([':search_term' => $searchTermLike]) // Bindings for count query
            ->willReturn(true);
        $this->stmtCountMock->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(1); // Simulate 1 snippet found with this search term

        // Data Query Mock
        $matchingSnippetId = Uuid::uuid4()->toString();
        $dbMatchingSnippets = [
            [
                'id' => $matchingSnippetId, 'title' => 'Title with unique search term', 'description' => 'Desc A', 
                'username' => 'UserA', 'language' => 'php', 'tags' => '{"searched","php"}', 
                'created_at' => '2024-03-10 10:00:00', 'updated_at' => '2024-03-10 10:00:00'
            ]
        ];

        // Order of bindValue calls: first from $bindings array, then :limit, then :offset
        $this->stmtDataMock->expects($this->exactly(3)) // :search_term, :limit, :offset
            ->method('bindValue')
            ->willReturnMap([
                [':search_term', $searchTermLike, PDO::PARAM_STR, true], // Assuming PARAM_STR by default
                [':limit', $requestedPerPage, PDO::PARAM_INT, true],
                [':offset', $expectedOffset, PDO::PARAM_INT, true],
            ]);
        $this->stmtDataMock->expects($this->once())
            ->method('execute')
            ->with() // No arguments as values are bound
            ->willReturn(true);
        $this->stmtDataMock->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbMatchingSnippets);

        // --- Expected Processed Snippets ---
        $processedSnippets = [
            [
                'id' => $matchingSnippetId, 'title' => 'Title with unique search term', 'description' => 'Desc A', 
                'username' => 'UserA', 'language' => 'php', 'tags' => ['searched', 'php'], 
                'created_at' => '2024-03-10 10:00:00', 'updated_at' => '2024-03-10 10:00:00'
            ]
        ];

        // --- Expected Pagination ---
        $expectedPagination = [
            'current_page' => $requestedPage,
            'per_page' => $requestedPerPage,
            'total_pages' => 1, // ceil(1 / 15)
            'total_items' => 1,
            'items_on_page' => count($processedSnippets) // Should be 1
        ];

        // --- Expected Response Data ---
        $expectedResponseData = [
            'data' => $processedSnippets,
            'pagination' => $expectedPagination
        ];

        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Retrieved snippets list.',
                [
                    'page' => $requestedPage, 'per_page' => $requestedPerPage, 'total_found' => 1,
                    'search_term' => $searchTerm, 'language_filter' => null, 'tag_filter' => null
                ]
            );

        // --- Mock Response ---
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData));

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testListRetrievalWithLanguageFilter()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        $filterLanguage = 'python';
        $requestedPage = 1;
        $requestedPerPage = 15;
        $expectedOffset = 0;

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['language' => $filterLanguage]);

        // --- Mock Database Interactions ---
        $expectedWhereClause = "WHERE language = :language";

        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalOr(
                $this->logicalAnd( // Count query with WHERE
                    $this->stringContains('SELECT COUNT(*) FROM code_snippets'),
                    $this->stringContains($expectedWhereClause)
                ),
                $this->logicalAnd( // Data query with WHERE
                    $this->stringContains('SELECT id, title, description, username, language, tags, created_at, updated_at FROM code_snippets'),
                    $this->stringContains($expectedWhereClause),
                    $this->stringContains('ORDER BY created_at DESC'),
                    $this->stringContains('LIMIT :limit OFFSET :offset')
                )
            ))
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'COUNT(*)') !== false) {
                    return $this->stmtCountMock;
                }
                return $this->stmtDataMock;
            });

        // Count Query Mock
        $this->stmtCountMock->expects($this->once())
            ->method('execute')
            ->with([':language' => $filterLanguage]) // Bindings for count query
            ->willReturn(true);
        $this->stmtCountMock->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(1); // Simulate 1 Python snippet found

        // Data Query Mock
        $matchingSnippetId = Uuid::uuid4()->toString();
        $dbMatchingSnippets = [
            [
                'id' => $matchingSnippetId, 'title' => 'Python Snippet', 'description' => 'A Python example', 
                'username' => 'PyUser', 'language' => 'python', 'tags' => '{"script","python"}', 
                'created_at' => '2024-03-11 10:00:00', 'updated_at' => '2024-03-11 10:00:00'
            ]
        ];

        $this->stmtDataMock->expects($this->exactly(3)) // :language, :limit, :offset
            ->method('bindValue')
            ->willReturnMap([
                [':language', $filterLanguage, PDO::PARAM_STR, true],
                [':limit', $requestedPerPage, PDO::PARAM_INT, true],
                [':offset', $expectedOffset, PDO::PARAM_INT, true],
            ]);
        $this->stmtDataMock->expects($this->once())
            ->method('execute')
            ->with() // No arguments as values are bound
            ->willReturn(true);
        $this->stmtDataMock->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbMatchingSnippets);

        // --- Expected Processed Snippets ---
        $processedSnippets = [
            [
                'id' => $matchingSnippetId, 'title' => 'Python Snippet', 'description' => 'A Python example', 
                'username' => 'PyUser', 'language' => 'python', 'tags' => ['script', 'python'], 
                'created_at' => '2024-03-11 10:00:00', 'updated_at' => '2024-03-11 10:00:00'
            ]
        ];

        // --- Expected Pagination ---
        $expectedPagination = [
            'current_page' => $requestedPage,
            'per_page' => $requestedPerPage,
            'total_pages' => 1, // ceil(1 / 15)
            'total_items' => 1,
            'items_on_page' => count($processedSnippets)
        ];

        // --- Expected Response Data ---
        $expectedResponseData = [
            'data' => $processedSnippets,
            'pagination' => $expectedPagination
        ];

        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Retrieved snippets list.',
                [
                    'page' => $requestedPage, 'per_page' => $requestedPerPage, 'total_found' => 1,
                    'search_term' => null, 'language_filter' => $filterLanguage, 'tag_filter' => null
                ]
            );

        // --- Mock Response ---
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData));

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testListRetrievalWithTagFilter()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        $filterTag = 'database';
        $filterTagLike = '%' . $filterTag . '%';
        $requestedPage = 1;
        $requestedPerPage = 15;
        $expectedOffset = 0;

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['tag' => $filterTag]);

        // --- Mock Database Interactions ---
        $expectedWhereClause = "WHERE tags::text ILIKE :tag_like";

        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalOr(
                $this->logicalAnd( // Count query with WHERE
                    $this->stringContains('SELECT COUNT(*) FROM code_snippets'),
                    $this->stringContains($expectedWhereClause)
                ),
                $this->logicalAnd( // Data query with WHERE
                    $this->stringContains('SELECT id, title, description, username, language, tags, created_at, updated_at FROM code_snippets'),
                    $this->stringContains($expectedWhereClause),
                    $this->stringContains('ORDER BY created_at DESC'),
                    $this->stringContains('LIMIT :limit OFFSET :offset')
                )
            ))
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'COUNT(*)') !== false) {
                    return $this->stmtCountMock;
                }
                return $this->stmtDataMock;
            });

        // Count Query Mock
        $this->stmtCountMock->expects($this->once())
            ->method('execute')
            ->with([':tag_like' => $filterTagLike]) // Bindings for count query
            ->willReturn(true);
        $this->stmtCountMock->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(1); // Simulate 1 snippet found with this tag

        // Data Query Mock
        $matchingSnippetId = Uuid::uuid4()->toString();
        $dbMatchingSnippets = [
            [
                'id' => $matchingSnippetId, 'title' => 'SQL Snippet', 'description' => 'A database query example', 
                'username' => 'DBAdmin', 'language' => 'sql', 'tags' => '{"query","database","optimization"}', 
                'created_at' => '2024-03-12 10:00:00', 'updated_at' => '2024-03-12 10:00:00'
            ]
        ];

        $this->stmtDataMock->expects($this->exactly(3)) // :tag_like, :limit, :offset
            ->method('bindValue')
            ->willReturnMap([
                [':tag_like', $filterTagLike, PDO::PARAM_STR, true],
                [':limit', $requestedPerPage, PDO::PARAM_INT, true],
                [':offset', $expectedOffset, PDO::PARAM_INT, true],
            ]);
        $this->stmtDataMock->expects($this->once())
            ->method('execute')
            ->with() // No arguments as values are bound
            ->willReturn(true);
        $this->stmtDataMock->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbMatchingSnippets);

        // --- Expected Processed Snippets ---
        $processedSnippets = [
            [
                'id' => $matchingSnippetId, 'title' => 'SQL Snippet', 'description' => 'A database query example', 
                'username' => 'DBAdmin', 'language' => 'sql', 'tags' => ['query', 'database', 'optimization'], 
                'created_at' => '2024-03-12 10:00:00', 'updated_at' => '2024-03-12 10:00:00'
            ]
        ];

        // --- Expected Pagination ---
        $expectedPagination = [
            'current_page' => $requestedPage,
            'per_page' => $requestedPerPage,
            'total_pages' => 1, // ceil(1 / 15)
            'total_items' => 1,
            'items_on_page' => count($processedSnippets)
        ];

        // --- Expected Response Data ---
        $expectedResponseData = [
            'data' => $processedSnippets,
            'pagination' => $expectedPagination
        ];

        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Retrieved snippets list.',
                [
                    'page' => $requestedPage, 'per_page' => $requestedPerPage, 'total_found' => 1,
                    'search_term' => null, 'language_filter' => null, 'tag_filter' => $filterTag
                ]
            );

        // --- Mock Response ---
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData));

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testListRetrievalWithValidSortOrder()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        $sortByParamValue = 'title';
        $sortOrderParamValue = 'ASC';
        $requestedPage = 1;
        $requestedPerPage = 15;
        $expectedOffset = 0;

        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['sort_by' => $sortByParamValue, 'order' => $sortOrderParamValue]);

        // Construct the EXACT expected SQL for the data query
        // Based on ListSnippetsAction.php:
        // $selectSql = "SELECT id, title, description, username, language, tags, created_at, updated_at ";
        // $baseSql = "FROM code_snippets";
        // $dataSql = $selectSql . $baseSql . " ORDER BY " . $sortBy . " " . $sortOrder . " LIMIT :limit OFFSET :offset";
        $expectedDataSql = "SELECT id, title, description, username, language, tags, created_at, updated_at "
                         . "FROM code_snippets"
                         . " ORDER BY " . $sortByParamValue . " " . $sortOrderParamValue
                         . " LIMIT :limit OFFSET :offset";

        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalOr(
                $this->stringContains('SELECT COUNT(*) FROM code_snippets'), // Count query
                $this->equalTo($expectedDataSql) // Data query - using equalTo for exact match
            ))
            ->willReturnCallback(function ($sql) use ($expectedDataSql) {
                if (strpos($sql, 'COUNT(*)') !== false) {
                    return $this->stmtCountMock;
                } elseif ($sql === $expectedDataSql) {
                    return $this->stmtDataMock;
                }
                // Fallback for debugging if 'with' isn't catching a mismatch properly
                // This should ideally not be hit if the ->with($this->logicalOr(...)) is correct
                fwrite(STDERR, "\nDEBUG: PDO::prepare mock callback received UNEXPECTED SQL: " . $sql . "\n");
                return $this->createMock(PDOStatement::class); // Return a dummy mock
            });

        // Count Query Mock
        $this->stmtCountMock->expects($this->once())
            ->method('execute')
            ->with([])
            ->willReturn(true);
        $this->stmtCountMock->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(2);

        // Data Query Mock
        $snippet1Id = Uuid::uuid4()->toString();
        $snippet2Id = Uuid::uuid4()->toString();
        $dbSnippetsSorted = [
            [
                'id' => $snippet1Id, 'title' => 'Alpha Snippet', 'language' => 'php', 'tags' => '{}', 
                'description' => 'Desc A', 'username' => 'UserA',
                'created_at' => '2024-03-10 10:00:00', 'updated_at' => '2024-03-10 10:00:00'
            ],
            [
                'id' => $snippet2Id, 'title' => 'Beta Snippet', 'language' => 'python', 'tags' => '{}', 
                'description' => 'Desc B', 'username' => 'UserB',
                'created_at' => '2024-03-09 11:00:00', 'updated_at' => '2024-03-09 11:00:00'
            ],
        ];

        $this->stmtDataMock->expects($this->exactly(2))
            ->method('bindValue')
            ->willReturnMap([
                [':limit', $requestedPerPage, PDO::PARAM_INT, true],
                [':offset', $expectedOffset, PDO::PARAM_INT, true],
            ]);
        $this->stmtDataMock->expects($this->once())
            ->method('execute')
            ->with()
            ->willReturn(true);
        $this->stmtDataMock->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbSnippetsSorted);

        $processedSnippets = [
            [
                'id' => $snippet1Id, 'title' => 'Alpha Snippet', 'language' => 'php', 'tags' => [], 
                'description' => 'Desc A', 'username' => 'UserA',
                'created_at' => '2024-03-10 10:00:00', 'updated_at' => '2024-03-10 10:00:00'
            ],
            [
                'id' => $snippet2Id, 'title' => 'Beta Snippet', 'language' => 'python', 'tags' => [], 
                'description' => 'Desc B', 'username' => 'UserB',
                'created_at' => '2024-03-09 11:00:00', 'updated_at' => '2024-03-09 11:00:00'
            ],
        ];
        
        $expectedPagination = [
            'current_page' => $requestedPage, 'per_page' => $requestedPerPage,
            'total_pages' => 1, 'total_items' => 2, 'items_on_page' => count($processedSnippets)
        ];
        $expectedResponseData = ['data' => $processedSnippets, 'pagination' => $expectedPagination];

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('Retrieved snippets list.', [
                'page' => $requestedPage, 'per_page' => $requestedPerPage, 'total_found' => 2,
                'search_term' => null, 'language_filter' => null, 'tag_filter' => null
            ]);
        
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData));

        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testListRetrievalWithInvalidSortByFieldDefaultsToCreatedAt()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        $invalidSortBy = 'non_existent_column';
        $requestedSortOrder = 'ASC'; // Let's specify ASC to see if it's respected with the default field
        
        $requestedPage = 1;
        $requestedPerPage = 15;
        $expectedOffset = 0;

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['sort_by' => $invalidSortBy, 'order' => $requestedSortOrder]);

        // --- Mock Database Interactions ---
        // Expecting sort by 'created_at ASC' because 'non_existent_column' is invalid,
        // and 'ASC' was the requested order.
        $expectedOrderByClause = "ORDER BY created_at " . $requestedSortOrder; 

        $expectedDataSql = "SELECT id, title, description, username, language, tags, created_at, updated_at "
                         . "FROM code_snippets"
                         . " " . $expectedOrderByClause // Note the space before ORDER BY
                         . " LIMIT :limit OFFSET :offset";

        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalOr(
                $this->stringContains('SELECT COUNT(*) FROM code_snippets'),
                $this->equalTo($expectedDataSql) // Exact match for data query
            ))
            ->willReturnCallback(function ($sql) use ($expectedDataSql) {
                if (strpos($sql, 'COUNT(*)') !== false) {
                    return $this->stmtCountMock;
                } elseif ($sql === $expectedDataSql) {
                    return $this->stmtDataMock;
                }
                // Fallback for debugging
                fwrite(STDERR, "\nDEBUG (InvalidSortBy): PDO::prepare mock callback UNEXPECTED SQL: " . $sql . "\n");
                return $this->createMock(PDOStatement::class); 
            });

        // Count Query Mock
        $this->stmtCountMock->expects($this->once())
            ->method('execute')
            ->with([])
            ->willReturn(true);
        $this->stmtCountMock->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(2); 

        // Data Query Mock
        $snippet1Id = Uuid::uuid4()->toString();
        $snippet2Id = Uuid::uuid4()->toString();
        // Data should be "pre-sorted" as if the DB returned it by created_at ASC
        $dbSnippetsSorted = [
            [ // Older created_at first for ASC
                'id' => $snippet2Id, 'title' => 'Beta Snippet', 'language' => 'python', 'tags' => '{}', 
                'description' => 'Desc B', 'username' => 'UserB',
                'created_at' => '2024-03-09 11:00:00', 'updated_at' => '2024-03-09 11:00:00'
            ],
            [ // Newer created_at second
                'id' => $snippet1Id, 'title' => 'Alpha Snippet', 'language' => 'php', 'tags' => '{}', 
                'description' => 'Desc A', 'username' => 'UserA',
                'created_at' => '2024-03-10 10:00:00', 'updated_at' => '2024-03-10 10:00:00'
            ],
        ];

        $this->stmtDataMock->expects($this->exactly(2)) 
            ->method('bindValue')
            ->willReturnMap([
                [':limit', $requestedPerPage, PDO::PARAM_INT, true],
                [':offset', $expectedOffset, PDO::PARAM_INT, true],
            ]);
        $this->stmtDataMock->expects($this->once())
            ->method('execute')
            ->with()
            ->willReturn(true);
        $this->stmtDataMock->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbSnippetsSorted);

        // --- Expected Processed Snippets (tags parsed) ---
        $processedSnippets = array_map(function($s) { $s['tags'] = []; return $s; }, $dbSnippetsSorted);

        // --- Expected Pagination ---
        $expectedPagination = [
            'current_page' => $requestedPage, 'per_page' => $requestedPerPage,
            'total_pages' => 1, 'total_items' => 2, 'items_on_page' => count($processedSnippets)
        ];
        $expectedResponseData = ['data' => $processedSnippets, 'pagination' => $expectedPagination];

        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Retrieved snippets list.',
                [
                    'page' => $requestedPage, 'per_page' => $requestedPerPage, 'total_found' => 2,
                    'search_term' => null, 'language_filter' => null, 'tag_filter' => null
                ]
            );
        
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData));

        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testListRetrievalWithSearchTermAndLanguageFilter()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        $searchTerm = 'api';
        $searchTermLike = '%' . $searchTerm . '%';
        $filterLanguage = 'php';
        
        $requestedPage = 1;
        $requestedPerPage = 15;
        $expectedOffset = 0;

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['search' => $searchTerm, 'language' => $filterLanguage]);

        // --- Mock Database Interactions ---
        $expectedWhereClauseSearch = "(title ILIKE :search_term OR description ILIKE :search_term OR username ILIKE :search_term)";
        $expectedWhereClauseLanguage = "language = :language";
        // The order of clauses in implode(" AND ", $whereClauses) matters for exact SQL match
        $expectedCombinedWhereClause = "WHERE " . $expectedWhereClauseSearch . " AND " . $expectedWhereClauseLanguage;

        $expectedDataSql = "SELECT id, title, description, username, language, tags, created_at, updated_at "
                         . "FROM code_snippets"
                         . " " . $expectedCombinedWhereClause // Note the space
                         . " ORDER BY created_at DESC" // Default sort
                         . " LIMIT :limit OFFSET :offset";
        
        $expectedCountSqlPdoString = "SELECT COUNT(*) FROM code_snippets " . $expectedCombinedWhereClause;


        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalOr(
                $this->equalTo($expectedCountSqlPdoString), // Exact match for count query
                $this->equalTo($expectedDataSql)      // Exact match for data query
            ))
            ->willReturnCallback(function ($sql) use ($expectedCountSqlPdoString, $expectedDataSql) {
                if ($sql === $expectedCountSqlPdoString) {
                    return $this->stmtCountMock;
                } elseif ($sql === $expectedDataSql) {
                    return $this->stmtDataMock;
                }
                fwrite(STDERR, "\nDEBUG (CombinedFilter): PDO::prepare mock callback UNEXPECTED SQL: " . $sql . "\n");
                return $this->createMock(PDOStatement::class);
            });
        
        $expectedBindings = [
            ':search_term' => $searchTermLike,
            ':language' => $filterLanguage
        ];

        // Count Query Mock
        $this->stmtCountMock->expects($this->once())
            ->method('execute')
            ->with($expectedBindings) // Both bindings
            ->willReturn(true);
        $this->stmtCountMock->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(1); // Simulate 1 snippet found

        // Data Query Mock
        $matchingSnippetId = Uuid::uuid4()->toString();
        $dbMatchingSnippets = [
            [
                'id' => $matchingSnippetId, 'title' => 'PHP API Client', 'description' => 'Client for an API in PHP', 
                'username' => 'phpdev', 'language' => 'php', 'tags' => '{"api","php"}', 
                'created_at' => '2024-03-13 10:00:00', 'updated_at' => '2024-03-13 10:00:00'
            ]
        ];

        // For data query, bindValue is called for each key in $bindings, then :limit, then :offset
        $this->stmtDataMock->expects($this->exactly(4)) // :search_term, :language, :limit, :offset
            ->method('bindValue')
            ->willReturnMap([
                [':search_term', $searchTermLike, PDO::PARAM_STR, true],
                [':language', $filterLanguage, PDO::PARAM_STR, true],
                [':limit', $requestedPerPage, PDO::PARAM_INT, true],
                [':offset', $expectedOffset, PDO::PARAM_INT, true],
            ]);
        $this->stmtDataMock->expects($this->once())
            ->method('execute')
            ->with() 
            ->willReturn(true);
        $this->stmtDataMock->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbMatchingSnippets);

        // --- Expected Processed Snippets ---
        $processedSnippets = [
            [
                'id' => $matchingSnippetId, 'title' => 'PHP API Client', 'description' => 'Client for an API in PHP', 
                'username' => 'phpdev', 'language' => 'php', 'tags' => ['api', 'php'], 
                'created_at' => '2024-03-13 10:00:00', 'updated_at' => '2024-03-13 10:00:00'
            ]
        ];

        // --- Expected Pagination ---
        $expectedPagination = [
            'current_page' => $requestedPage, 'per_page' => $requestedPerPage,
            'total_pages' => 1, 'total_items' => 1, 'items_on_page' => count($processedSnippets)
        ];
        $expectedResponseData = ['data' => $processedSnippets, 'pagination' => $expectedPagination];

        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Retrieved snippets list.',
                [
                    'page' => $requestedPage, 'per_page' => $requestedPerPage, 'total_found' => 1,
                    'search_term' => $searchTerm, 'language_filter' => $filterLanguage, 'tag_filter' => null
                ]
            );

        // --- Mock Response ---
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData));

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testPdoExceptionDuringCountQuery()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        // --- Mock Request (default params) ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([]);

        // --- Mock Database Interactions to throw PDOException on count query ---
        $exceptionMessage = "DB count error";
        $pdoException = new \PDOException($exceptionMessage);

        // Expect prepare for the count query
        $this->pdoMock->expects($this->once()) // Only prepare for count is expected before exception
            ->method('prepare')
            ->with($this->stringContains('SELECT COUNT(*) FROM code_snippets'))
            ->willReturn($this->stmtCountMock);

        // Expect execute on stmtCountMock to throw the exception
        $this->stmtCountMock->expects($this->once())
            ->method('execute')
            // ->with([]) // Bindings might or might not be set before an exception
            ->willThrowException($pdoException);
            
        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                "Database error in ListSnippetsAction: " . $exceptionMessage,
                $this->callback(function ($context) use ($pdoException) {
                    return isset($context['exception']) && $context['exception'] === $pdoException;
                })
            );

        // --- Expected Response ---
        $expectedErrorPayload = ['error' => 'Failed to retrieve snippets due to a database issue.'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorPayload));
        
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);
        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(500)
            ->willReturn($this->responseMock);

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testPdoExceptionDuringDataQuery()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        // --- Mock Request (default params) ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([]);

        // --- Mock Database Interactions ---
        $exceptionMessage = "DB data query error";
        $pdoException = new \PDOException($exceptionMessage);

        // 1. Count Query (Successful)
        $this->pdoMock->expects($this->atLeastOnce()) // Called for count and data
            ->method('prepare')
            ->with($this->logicalOr(
                $this->stringContains('SELECT COUNT(*) FROM code_snippets'),
                $this->stringContains('SELECT id, title, description, username, language, tags, created_at, updated_at FROM code_snippets') 
            ))
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'COUNT(*)') !== false) {
                    return $this->stmtCountMock;
                }
                // This will be for the data query
                return $this->stmtDataMock; 
            });

        $this->stmtCountMock->expects($this->once())
            ->method('execute')
            ->with([]) // Assuming no bindings for default count
            ->willReturn(true);
        $this->stmtCountMock->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(10); // Simulate some count

        // 2. Data Query (Throws PDOException)
        // stmtDataMock is returned by the callback above for the data SQL
        
        // The action first binds parameters, then executes.
        // We can make either bindValue or execute throw the exception.
        // Let's make execute() throw it.
        $this->stmtDataMock->expects($this->any()) // Allow bindValue to be called
            ->method('bindValue'); 
            // ->willReturn(true); // Not strictly necessary if we don't assert on its return

        $this->stmtDataMock->expects($this->once())
            ->method('execute')
            ->willThrowException($pdoException);
            
        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                "Database error in ListSnippetsAction: " . $exceptionMessage,
                $this->callback(function ($context) use ($pdoException) {
                    return isset($context['exception']) && $context['exception'] === $pdoException;
                })
            );

        // --- Expected Response ---
        $expectedErrorPayload = ['error' => 'Failed to retrieve snippets due to a database issue.'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorPayload));
        
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);
        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(500)
            ->willReturn($this->responseMock);

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testListRetrievalWithFiltersResultingInNoSnippets()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        $filterLanguage = 'non_existent_language'; // A filter likely to return no results
        $requestedPage = 1;
        $requestedPerPage = 15;
        $expectedOffset = 0;

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['language' => $filterLanguage]);

        // --- Mock Database Interactions ---
        $expectedWhereClause = "WHERE language = :language";
        $expectedCountSql = "SELECT COUNT(*) FROM code_snippets " . $expectedWhereClause;
        $expectedDataSql = "SELECT id, title, description, username, language, tags, created_at, updated_at "
                         . "FROM code_snippets"
                         . " " . $expectedWhereClause
                         . " ORDER BY created_at DESC" // Default sort
                         . " LIMIT :limit OFFSET :offset";

        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalOr(
                $this->equalTo($expectedCountSql),
                $this->equalTo($expectedDataSql)
            ))
            ->willReturnCallback(function ($sql) use ($expectedCountSql, $expectedDataSql) {
                if ($sql === $expectedCountSql) {
                    return $this->stmtCountMock;
                } elseif ($sql === $expectedDataSql) {
                    return $this->stmtDataMock;
                }
                fwrite(STDERR, "\nDEBUG (NoResults): PDO::prepare mock callback UNEXPECTED SQL: " . $sql . "\n");
                return $this->createMock(PDOStatement::class);
            });

        // Count Query Mock - returns 0
        $this->stmtCountMock->expects($this->once())
            ->method('execute')
            ->with([':language' => $filterLanguage])
            ->willReturn(true);
        $this->stmtCountMock->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(0); // Simulate 0 snippets found

        // Data Query Mock - fetchAll returns empty array
        // Bindings for data query
        $this->stmtDataMock->expects($this->exactly(3)) // :language, :limit, :offset
            ->method('bindValue')
            ->willReturnMap([
                [':language', $filterLanguage, PDO::PARAM_STR, true],
                [':limit', $requestedPerPage, PDO::PARAM_INT, true],
                [':offset', $expectedOffset, PDO::PARAM_INT, true],
            ]);
        $this->stmtDataMock->expects($this->once())
            ->method('execute')
            ->with()
            ->willReturn(true);
        $this->stmtDataMock->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]); // No snippets match

        // --- Expected Processed Snippets ---
        $processedSnippets = []; // Empty array

        // --- Expected Pagination ---
        // totalPages = ceil(0 / 15) which is 0.
        $expectedPagination = [
            'current_page' => $requestedPage,
            'per_page' => $requestedPerPage,
            'total_pages' => 0, 
            'total_items' => 0,
            'items_on_page' => 0
        ];

        // --- Expected Response Data ---
        $expectedResponseData = [
            'data' => $processedSnippets,
            'pagination' => $expectedPagination
        ];

        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Retrieved snippets list.',
                [
                    'page' => $requestedPage, 'per_page' => $requestedPerPage, 'total_found' => 0,
                    'search_term' => null, 'language_filter' => $filterLanguage, 'tag_filter' => null
                ]
            );
        
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData));

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testListRetrievalWithValidSortByAndInvalidOrderDefaultsToDesc()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        $validSortBy = 'title';
        $invalidSortOrder = 'foo'; // An invalid sort order value
        
        $requestedPage = 1;
        $requestedPerPage = 15;
        $expectedOffset = 0;

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['sort_by' => $validSortBy, 'order' => $invalidSortOrder]);

        // --- Mock Database Interactions ---
        // Expecting sort by 'title DESC' because 'foo' is invalid for order.
        $expectedOrderByClause = "ORDER BY " . $validSortBy . " DESC"; 

        $expectedDataSql = "SELECT id, title, description, username, language, tags, created_at, updated_at "
                         . "FROM code_snippets"
                         . " " . $expectedOrderByClause
                         . " LIMIT :limit OFFSET :offset";
        
        $expectedCountSql = "SELECT COUNT(*) FROM code_snippets"; // No WHERE clause

        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalOr(
                $this->equalTo($expectedCountSql),
                $this->equalTo($expectedDataSql) 
            ))
            ->willReturnCallback(function ($sql) use ($expectedCountSql, $expectedDataSql) {
                if ($sql === $expectedCountSql) {
                    return $this->stmtCountMock;
                } elseif ($sql === $expectedDataSql) {
                    return $this->stmtDataMock;
                }
                fwrite(STDERR, "\nDEBUG (InvalidOrder): PDO::prepare mock callback UNEXPECTED SQL: " . $sql . "\n");
                return $this->createMock(PDOStatement::class); 
            });

        // Count Query Mock
        $this->stmtCountMock->expects($this->once())
            ->method('execute')
            ->with([])
            ->willReturn(true);
        $this->stmtCountMock->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(2); 

        // Data Query Mock
        $snippet1Id = Uuid::uuid4()->toString();
        $snippet2Id = Uuid::uuid4()->toString();
        // Data should be "pre-sorted" as if the DB returned it by title DESC
        $dbSnippetsSorted = [
            [ // Title 'Beta Snippet' comes before 'Alpha Snippet' in DESC order
                'id' => $snippet2Id, 'title' => 'Beta Snippet', 'language' => 'python', 'tags' => '{}', 
                'description' => 'Desc B', 'username' => 'UserB',
                'created_at' => '2024-03-09 11:00:00', 'updated_at' => '2024-03-09 11:00:00'
            ],
            [ 
                'id' => $snippet1Id, 'title' => 'Alpha Snippet', 'language' => 'php', 'tags' => '{}', 
                'description' => 'Desc A', 'username' => 'UserA',
                'created_at' => '2024-03-10 10:00:00', 'updated_at' => '2024-03-10 10:00:00'
            ],
        ];

        $this->stmtDataMock->expects($this->exactly(2)) 
            ->method('bindValue')
            ->willReturnMap([
                [':limit', $requestedPerPage, PDO::PARAM_INT, true],
                [':offset', $expectedOffset, PDO::PARAM_INT, true],
            ]);
        $this->stmtDataMock->expects($this->once())
            ->method('execute')
            ->with()
            ->willReturn(true);
        $this->stmtDataMock->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbSnippetsSorted);

        $processedSnippets = array_map(function($s) { $s['tags'] = []; return $s; }, $dbSnippetsSorted);

        $expectedPagination = [
            'current_page' => $requestedPage, 'per_page' => $requestedPerPage,
            'total_pages' => 1, 'total_items' => 2, 'items_on_page' => count($processedSnippets)
        ];
        $expectedResponseData = ['data' => $processedSnippets, 'pagination' => $expectedPagination];

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Retrieved snippets list.',
                [
                    'page' => $requestedPage, 'per_page' => $requestedPerPage, 'total_found' => 2,
                    'search_term' => null, 'language_filter' => null, 'tag_filter' => null
                ]
            );
        
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData));

        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testListRetrievalWithPageLessThanOneDefaultsToOne()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        $invalidPage = 0;
        $expectedClampedPage = 1;
        $requestedPerPage = 10; // A standard per_page
        $expectedOffset = ($expectedClampedPage - 1) * $requestedPerPage; // (1 - 1) * 10 = 0

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['page' => (string)$invalidPage, 'per_page' => (string)$requestedPerPage]);

        // --- Mock Database Interactions ---
        $expectedDataSql = "SELECT id, title, description, username, language, tags, created_at, updated_at "
                         . "FROM code_snippets"
                         . " ORDER BY created_at DESC" // Default sort
                         . " LIMIT :limit OFFSET :offset";
        $expectedCountSql = "SELECT COUNT(*) FROM code_snippets";

        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalOr(
                $this->equalTo($expectedCountSql),
                $this->equalTo($expectedDataSql)
            ))
            ->willReturnCallback(function ($sql) use ($expectedCountSql, $expectedDataSql) {
                if ($sql === $expectedCountSql) {
                    return $this->stmtCountMock;
                } elseif ($sql === $expectedDataSql) {
                    return $this->stmtDataMock;
                }
                return $this->createMock(PDOStatement::class);
            });

        // Count Query Mock
        $this->stmtCountMock->expects($this->once())->method('execute')->with([])->willReturn(true);
        $this->stmtCountMock->expects($this->once())->method('fetchColumn')->willReturn(5); // Simulate 5 total items

        // Data Query Mock - verify :offset uses the clamped page
        $this->stmtDataMock->expects($this->exactly(2))
            ->method('bindValue')
            ->willReturnMap([
                [':limit', $requestedPerPage, PDO::PARAM_INT, true],
                [':offset', $expectedOffset, PDO::PARAM_INT, true], // Crucial check for offset
            ]);
        $this->stmtDataMock->expects($this->once())->method('execute')->with()->willReturn(true);
        $this->stmtDataMock->expects($this->once())->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn(
            array_fill(0, min(5, $requestedPerPage), ['id' => 'test', 'title' => 'Test', 'tags' => '{}']) // Dummy data
        );

        // --- Expected Pagination ---
        $expectedPagination = [
            'current_page' => $expectedClampedPage, // Page should be 1
            'per_page' => $requestedPerPage,
            'total_pages' => 1, // ceil(5 / 10)
            'total_items' => 5,
            'items_on_page' => min(5, $requestedPerPage)
        ];
        $expectedResponseData = [
            'data' => array_fill(0, min(5, $requestedPerPage), ['id' => 'test', 'title' => 'Test', 'tags' => []]),
            'pagination' => $expectedPagination
        ];

        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('Retrieved snippets list.', $this->callback(function ($context) use ($expectedClampedPage, $requestedPerPage) {
                return $context['page'] === $expectedClampedPage && $context['per_page'] === $requestedPerPage;
            }));
        
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData));

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testListRetrievalWithPerPageLessThanOneDefaultsToOne()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        $requestedPage = 2; 
        $invalidPerPage = 0;
        $expectedClampedPerPage = 1;
        // Offset should use the clamped per_page: (2 - 1) * 1 = 1
        $expectedOffset = ($requestedPage - 1) * $expectedClampedPerPage; 

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['page' => (string)$requestedPage, 'per_page' => (string)$invalidPerPage]);

        // --- Mock Database Interactions ---
        $expectedDataSql = "SELECT id, title, description, username, language, tags, created_at, updated_at "
                         . "FROM code_snippets"
                         . " ORDER BY created_at DESC" // Default sort
                         . " LIMIT :limit OFFSET :offset";
        $expectedCountSql = "SELECT COUNT(*) FROM code_snippets";

        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalOr(
                $this->equalTo($expectedCountSql),
                $this->equalTo($expectedDataSql)
            ))
            ->willReturnCallback(function ($sql) use ($expectedCountSql, $expectedDataSql) {
                if ($sql === $expectedCountSql) {
                    return $this->stmtCountMock;
                } elseif ($sql === $expectedDataSql) {
                    return $this->stmtDataMock;
                }
                return $this->createMock(PDOStatement::class);
            });

        // Count Query Mock
        $this->stmtCountMock->expects($this->once())->method('execute')->with([])->willReturn(true);
        $this->stmtCountMock->expects($this->once())->method('fetchColumn')->willReturn(5); // Simulate 5 total items

        // Data Query Mock - verify :limit and :offset use the clamped per_page
        $this->stmtDataMock->expects($this->exactly(2))
            ->method('bindValue')
            ->willReturnMap([
                [':limit', $expectedClampedPerPage, PDO::PARAM_INT, true], // Crucial check for limit
                [':offset', $expectedOffset, PDO::PARAM_INT, true],       // Crucial check for offset
            ]);
        $this->stmtDataMock->expects($this->once())->method('execute')->with()->willReturn(true);
        // FetchAll should return 1 item because per_page is clamped to 1, and we are on page 2 (offset 1)
        $mockedDbResults = [['id' => 'item2', 'title' => 'Item 2', 'tags' => '{}']]; // Simulating item at offset 1
        if ($expectedOffset >= 5) { // If offset is beyond total items
            $mockedDbResults = [];
        }

        $this->stmtDataMock->expects($this->once())->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn($mockedDbResults);

        // --- Expected Pagination ---
        $expectedItemsOnPage = count($mockedDbResults);
        $expectedTotalPages = ceil(5 / $expectedClampedPerPage); // ceil(5 / 1) = 5

        $expectedPagination = [
            'current_page' => $requestedPage,
            'per_page' => $expectedClampedPerPage, // PerPage should be 1
            'total_pages' => (int)$expectedTotalPages,
            'total_items' => 5,
            'items_on_page' => $expectedItemsOnPage
        ];
        
        $processedMockedResults = array_map(function($item) { $item['tags'] = []; return $item; }, $mockedDbResults);
        $expectedResponseData = [
            'data' => $processedMockedResults,
            'pagination' => $expectedPagination
        ];

        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('Retrieved snippets list.', $this->callback(function ($context) use ($requestedPage, $expectedClampedPerPage) {
                return $context['page'] === $requestedPage && $context['per_page'] === $expectedClampedPerPage;
            }));
        
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData));

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testListRetrievalWithPerPageGreaterThanMaxDefaultsToMax()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        $requestedPage = 1; 
        $invalidPerPage = 150; // Greater than max
        $expectedClampedPerPage = 100; // Should be clamped to 100
        $expectedOffset = ($requestedPage - 1) * $expectedClampedPerPage; // (1 - 1) * 100 = 0

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['page' => (string)$requestedPage, 'per_page' => (string)$invalidPerPage]);

        // --- Mock Database Interactions ---
        $expectedDataSql = "SELECT id, title, description, username, language, tags, created_at, updated_at "
                         . "FROM code_snippets"
                         . " ORDER BY created_at DESC" // Default sort
                         . " LIMIT :limit OFFSET :offset";
        $expectedCountSql = "SELECT COUNT(*) FROM code_snippets";

        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalOr(
                $this->equalTo($expectedCountSql),
                $this->equalTo($expectedDataSql)
            ))
            ->willReturnCallback(function ($sql) use ($expectedCountSql, $expectedDataSql) {
                if ($sql === $expectedCountSql) {
                    return $this->stmtCountMock;
                } elseif ($sql === $expectedDataSql) {
                    return $this->stmtDataMock;
                }
                return $this->createMock(PDOStatement::class);
            });

        // Count Query Mock
        $this->stmtCountMock->expects($this->once())->method('execute')->with([])->willReturn(true);
        $this->stmtCountMock->expects($this->once())->method('fetchColumn')->willReturn(200); // Simulate 200 total items

        // Data Query Mock - verify :limit and :offset use the clamped per_page
        $this->stmtDataMock->expects($this->exactly(2))
            ->method('bindValue')
            ->willReturnMap([
                [':limit', $expectedClampedPerPage, PDO::PARAM_INT, true], // Crucial check for limit (100)
                [':offset', $expectedOffset, PDO::PARAM_INT, true],
            ]);
        $this->stmtDataMock->expects($this->once())->method('execute')->with()->willReturn(true);
        // FetchAll should return up to 100 items
        $mockedDbResults = array_fill(0, min(200, $expectedClampedPerPage), ['id' => 'test', 'title' => 'Test', 'tags' => '{}']);
        $this->stmtDataMock->expects($this->once())->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn($mockedDbResults);

        // --- Expected Pagination ---
        $expectedItemsOnPage = count($mockedDbResults); // Should be 100
        $expectedTotalPages = ceil(200 / $expectedClampedPerPage); // ceil(200 / 100) = 2

        $expectedPagination = [
            'current_page' => $requestedPage,
            'per_page' => $expectedClampedPerPage, // PerPage should be 100
            'total_pages' => (int)$expectedTotalPages,
            'total_items' => 200,
            'items_on_page' => $expectedItemsOnPage
        ];
        
        $processedMockedResults = array_map(function($item) { $item['tags'] = []; return $item; }, $mockedDbResults);
        $expectedResponseData = [
            'data' => $processedMockedResults,
            'pagination' => $expectedPagination
        ];

        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('Retrieved snippets list.', $this->callback(function ($context) use ($requestedPage, $expectedClampedPerPage) {
                return $context['page'] === $requestedPage && $context['per_page'] === $expectedClampedPerPage;
            }));
        
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData));

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

     public function testListRetrievalWithComplexTagParsing()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        $requestedPage = 1;
        $requestedPerPage = 15;
        $expectedOffset = 0;

        // --- Mock Request (default) ---
        $this->requestMock->expects($this->once())->method('getQueryParams')->willReturn([]);

        // --- Mock Database Interactions ---
        $expectedDataSql = "SELECT id, title, description, username, language, tags, created_at, updated_at "
                         . "FROM code_snippets"
                         . " ORDER BY created_at DESC"
                         . " LIMIT :limit OFFSET :offset";
        $expectedCountSql = "SELECT COUNT(*) FROM code_snippets";

        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalOr($this->equalTo($expectedCountSql), $this->equalTo($expectedDataSql)))
            ->willReturnCallback(function ($sql) use ($expectedCountSql, $expectedDataSql) {
                if ($sql === $expectedCountSql) return $this->stmtCountMock;
                if ($sql === $expectedDataSql) return $this->stmtDataMock;
                return $this->createMock(PDOStatement::class);
            });

        $this->stmtCountMock->expects($this->once())->method('execute')->with([])->willReturn(true);
        $this->stmtCountMock->expects($this->once())->method('fetchColumn')->willReturn(2); // Two snippets

        // Snippets with various tag string formats
        $snippet1Id = Uuid::uuid4()->toString();
        $snippet2Id = Uuid::uuid4()->toString();
        $dbSnippetsWithComplexTags = [
            [
                'id' => $snippet1Id, 'title' => 'Snippet 1', 'description' => 'Desc 1', 'username' => 'User1', 'language' => 'php',
                // DB stores: {"tag one","another-tag","last"}
                'tags' => '{"tag one","another-tag","last"}', 
                'created_at' => '2024-03-10 10:00:00', 'updated_at' => '2024-03-10 10:00:00'
            ],
            [
                'id' => $snippet2Id, 'title' => 'Snippet 2', 'description' => 'Desc 2', 'username' => 'User2', 'language' => 'js',
                // DB stores: {"tag with \"quote\"","tag with \\backslash\\","","empty string was here"}
                'tags' => '{"tag with \\"quote\\"","tag with \\\\backslash\\\\","","empty string was here"}', 
                'created_at' => '2024-03-11 10:00:00', 'updated_at' => '2024-03-11 10:00:00'
            ],
        ];

        $this->stmtDataMock->expects($this->exactly(2))
            ->method('bindValue')
            ->willReturnMap([
                [':limit', $requestedPerPage, PDO::PARAM_INT, true],
                [':offset', $expectedOffset, PDO::PARAM_INT, true],
            ]);
        $this->stmtDataMock->expects($this->once())->method('execute')->with()->willReturn(true);
        $this->stmtDataMock->expects($this->once())->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn($dbSnippetsWithComplexTags);

        // --- Expected Processed Snippets (focus on parsed tags) ---
        $expectedProcessedSnippets = [
            [
                'id' => $snippet1Id, 'title' => 'Snippet 1', 'description' => 'Desc 1', 'username' => 'User1', 'language' => 'php',
                'tags' => ["tag one", "another-tag", "last"], // Parsed from '{"tag one","another-tag","last"}'
                'created_at' => '2024-03-10 10:00:00', 'updated_at' => '2024-03-10 10:00:00'
            ],
            [
                'id' => $snippet2Id, 'title' => 'Snippet 2', 'description' => 'Desc 2', 'username' => 'User2', 'language' => 'js',
                // Parsed from '{"tag with \\"quote\\"","tag with \\\\backslash\\\\","","empty string was here"}'
                'tags' => ["tag with \"quote\"", "tag with \\backslash\\", "", "empty string was here"], 
                'created_at' => '2024-03-11 10:00:00', 'updated_at' => '2024-03-11 10:00:00'
            ],
        ];

        // --- Expected Pagination ---
        $expectedPagination = [
            'current_page' => $requestedPage, 'per_page' => $requestedPerPage,
            'total_pages' => 1, 'total_items' => 2, 'items_on_page' => 2
        ];
        $expectedResponseData = ['data' => $expectedProcessedSnippets, 'pagination' => $expectedPagination];

        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())->method('info');
        
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData)); // This will assert the entire structure including parsed tags

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testListRetrievalWithSearchTermTrimming()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        $rawSearchTerm = '  spaced term  ';
        $trimmedSearchTerm = trim($rawSearchTerm);
        $trimmedSearchTermLike = '%' . $trimmedSearchTerm . '%';
        
        $requestedPage = 1;
        $requestedPerPage = 15;
        $expectedOffset = 0;

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['search' => $rawSearchTerm]);

        // --- Mock Database Interactions ---
        $expectedWhereClauseSearch = "(title ILIKE :search_term OR description ILIKE :search_term OR username ILIKE :search_term)";
        $expectedCombinedWhereClause = "WHERE " . $expectedWhereClauseSearch;

        $expectedDataSql = "SELECT id, title, description, username, language, tags, created_at, updated_at "
                         . "FROM code_snippets"
                         . " " . $expectedCombinedWhereClause
                         . " ORDER BY created_at DESC"
                         . " LIMIT :limit OFFSET :offset";
        $expectedCountSql = "SELECT COUNT(*) FROM code_snippets " . $expectedCombinedWhereClause;

        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalOr(
                $this->equalTo($expectedCountSql),
                $this->equalTo($expectedDataSql)
            ))
            ->willReturnCallback(function ($sql) use ($expectedCountSql, $expectedDataSql) {
                if ($sql === $expectedCountSql) return $this->stmtCountMock;
                if ($sql === $expectedDataSql) return $this->stmtDataMock;
                return $this->createMock(PDOStatement::class);
            });
        
        // Count Query Mock - uses trimmed term for binding
        $this->stmtCountMock->expects($this->once())
            ->method('execute')
            ->with([':search_term' => $trimmedSearchTermLike]) // Check binding uses trimmed term
            ->willReturn(true);
        $this->stmtCountMock->expects($this->once())->method('fetchColumn')->willReturn(1);

        // Data Query Mock - uses trimmed term for binding
        $this->stmtDataMock->expects($this->exactly(3)) // :search_term, :limit, :offset
            ->method('bindValue')
            ->willReturnMap([
                [':search_term', $trimmedSearchTermLike, PDO::PARAM_STR, true], // Check binding uses trimmed term
                [':limit', $requestedPerPage, PDO::PARAM_INT, true],
                [':offset', $expectedOffset, PDO::PARAM_INT, true],
            ]);
        $this->stmtDataMock->expects($this->once())->method('execute')->with()->willReturn(true);
        $dbSnippet = [['id' => 's1', 'title' => 'Found spaced term', 'tags' => '{}']];
        $this->stmtDataMock->expects($this->once())->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn($dbSnippet);

        // --- Expected Response Data ---
        $processedSnippet = [['id' => 's1', 'title' => 'Found spaced term', 'tags' => []]];
        $expectedPagination = ['current_page' => 1, 'per_page' => 15, 'total_pages' => 1, 'total_items' => 1, 'items_on_page' => 1];
        $expectedResponseData = ['data' => $processedSnippet, 'pagination' => $expectedPagination];

        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Retrieved snippets list.',
                $this->callback(function ($context) use ($rawSearchTerm) {
                    // Check logger uses the raw, untrimmed search term
                    return isset($context['search_term']) && $context['search_term'] === $rawSearchTerm;
                })
            );
        
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData));

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

     public function testListRetrievalWithLanguageFilterTrimming()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        $rawLanguageFilter = '  php  ';
        $trimmedLanguageFilter = trim($rawLanguageFilter);
        
        $requestedPage = 1;
        $requestedPerPage = 15;
        $expectedOffset = 0;

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['language' => $rawLanguageFilter]);

        // --- Mock Database Interactions ---
        $expectedWhereClause = "WHERE language = :language";
        $expectedDataSql = "SELECT id, title, description, username, language, tags, created_at, updated_at "
                         . "FROM code_snippets"
                         . " " . $expectedWhereClause
                         . " ORDER BY created_at DESC"
                         . " LIMIT :limit OFFSET :offset";
        $expectedCountSql = "SELECT COUNT(*) FROM code_snippets " . $expectedWhereClause;

        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalOr(
                $this->equalTo($expectedCountSql),
                $this->equalTo($expectedDataSql)
            ))
            ->willReturnCallback(function ($sql) use ($expectedCountSql, $expectedDataSql) {
                if ($sql === $expectedCountSql) return $this->stmtCountMock;
                if ($sql === $expectedDataSql) return $this->stmtDataMock;
                return $this->createMock(PDOStatement::class);
            });
        
        // Count Query Mock - uses trimmed language for binding
        $this->stmtCountMock->expects($this->once())
            ->method('execute')
            ->with([':language' => $trimmedLanguageFilter]) // Check binding uses trimmed language
            ->willReturn(true);
        $this->stmtCountMock->expects($this->once())->method('fetchColumn')->willReturn(1);

        // Data Query Mock - uses trimmed language for binding
        $this->stmtDataMock->expects($this->exactly(3)) // :language, :limit, :offset
            ->method('bindValue')
            ->willReturnMap([
                [':language', $trimmedLanguageFilter, PDO::PARAM_STR, true], // Check binding uses trimmed language
                [':limit', $requestedPerPage, PDO::PARAM_INT, true],
                [':offset', $expectedOffset, PDO::PARAM_INT, true],
            ]);
        $this->stmtDataMock->expects($this->once())->method('execute')->with()->willReturn(true);
        $dbSnippet = [['id' => 'l1', 'title' => 'PHP Snippet', 'language' => 'php', 'tags' => '{}']];
        $this->stmtDataMock->expects($this->once())->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn($dbSnippet);

        // --- Expected Response Data ---
        $processedSnippet = [['id' => 'l1', 'title' => 'PHP Snippet', 'language' => 'php', 'tags' => []]];
        $expectedPagination = ['current_page' => 1, 'per_page' => 15, 'total_pages' => 1, 'total_items' => 1, 'items_on_page' => 1];
        $expectedResponseData = ['data' => $processedSnippet, 'pagination' => $expectedPagination];

        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Retrieved snippets list.',
                $this->callback(function ($context) use ($rawLanguageFilter) {
                    // Check logger uses the raw, untrimmed language filter
                    return isset($context['language_filter']) && $context['language_filter'] === $rawLanguageFilter;
                })
            );
        
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData));

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testListRetrievalWithTagFilterTrimming()
    {
        $action = new ListSnippetsAction($this->pdoMock, $this->loggerMock);

        $rawTagFilter = '  database  ';
        $trimmedTagFilter = trim($rawTagFilter);
        $trimmedTagFilterLike = '%' . $trimmedTagFilter . '%';
        
        $requestedPage = 1;
        $requestedPerPage = 15;
        $expectedOffset = 0;

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['tag' => $rawTagFilter]);

        // --- Mock Database Interactions ---
        $expectedWhereClause = "WHERE tags::text ILIKE :tag_like";
        $expectedDataSql = "SELECT id, title, description, username, language, tags, created_at, updated_at "
                         . "FROM code_snippets"
                         . " " . $expectedWhereClause
                         . " ORDER BY created_at DESC"
                         . " LIMIT :limit OFFSET :offset";
        $expectedCountSql = "SELECT COUNT(*) FROM code_snippets " . $expectedWhereClause;

        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalOr(
                $this->equalTo($expectedCountSql),
                $this->equalTo($expectedDataSql)
            ))
            ->willReturnCallback(function ($sql) use ($expectedCountSql, $expectedDataSql) {
                if ($sql === $expectedCountSql) return $this->stmtCountMock;
                if ($sql === $expectedDataSql) return $this->stmtDataMock;
                return $this->createMock(PDOStatement::class);
            });
        
        // Count Query Mock - uses trimmed tag for binding
        $this->stmtCountMock->expects($this->once())
            ->method('execute')
            ->with([':tag_like' => $trimmedTagFilterLike]) // Check binding uses trimmed tag
            ->willReturn(true);
        $this->stmtCountMock->expects($this->once())->method('fetchColumn')->willReturn(1);

        // Data Query Mock - uses trimmed tag for binding
        $this->stmtDataMock->expects($this->exactly(3)) // :tag_like, :limit, :offset
            ->method('bindValue')
            ->willReturnMap([
                [':tag_like', $trimmedTagFilterLike, PDO::PARAM_STR, true], // Check binding uses trimmed tag
                [':limit', $requestedPerPage, PDO::PARAM_INT, true],
                [':offset', $expectedOffset, PDO::PARAM_INT, true],
            ]);
        $this->stmtDataMock->expects($this->once())->method('execute')->with()->willReturn(true);
        $dbSnippet = [['id' => 't1', 'title' => 'SQL Snippet', 'tags' => '{"database"}']];
        $this->stmtDataMock->expects($this->once())->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn($dbSnippet);

        // --- Expected Response Data ---
        $processedSnippet = [['id' => 't1', 'title' => 'SQL Snippet', 'tags' => ['database']]];
        $expectedPagination = ['current_page' => 1, 'per_page' => 15, 'total_pages' => 1, 'total_items' => 1, 'items_on_page' => 1];
        $expectedResponseData = ['data' => $processedSnippet, 'pagination' => $expectedPagination];

        // --- Mock Logger ---
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Retrieved snippets list.',
                $this->callback(function ($context) use ($rawTagFilter) {
                    // Check logger uses the raw, untrimmed tag filter
                    return isset($context['tag_filter']) && $context['tag_filter'] === $rawTagFilter;
                })
            );
        
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedResponseData));

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);
        $this->assertSame($this->responseMock, $returnedResponse);
    }
}


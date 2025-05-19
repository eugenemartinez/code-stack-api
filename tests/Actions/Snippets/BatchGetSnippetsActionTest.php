<?php

namespace App\Tests\Actions\Snippets;

use App\Actions\Snippets\BatchGetSnippetsAction;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Ramsey\Uuid\Uuid; // Though not directly used in this first test, good to have for later

class BatchGetSnippetsActionTest extends TestCase
{
    private $pdoMock;
    private $stmtMock;
    private $requestMock;
    private $responseMock;
    private $streamMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdoMock = $this->createMock(PDO::class);
        $this->stmtMock = $this->createMock(PDOStatement::class);
        $this->requestMock = $this->createMock(ServerRequestInterface::class);
        $this->responseMock = $this->createMock(ResponseInterface::class);
        $this->streamMock = $this->createMock(StreamInterface::class);

        $this->responseMock->method('getBody')->willReturn($this->streamMock);
        // Common expectation for successful chaining, can be overridden if a test doesn't chain
        $this->responseMock->method('withHeader')->willReturn($this->responseMock);
        $this->responseMock->method('withStatus')->willReturn($this->responseMock);
    }

    public function testMissingIdsFieldReturns400()
    {
        $action = new BatchGetSnippetsAction($this->pdoMock);

        // --- Mock Request to have no 'ids' field ---
        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([]); // Empty body, so 'ids' is not set

        // --- Mock Response for 400 error ---
        $expectedErrorPayload = ['error' => 'Missing or invalid "ids" field. It should be an array of snippet IDs.'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorPayload));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($this->responseMock);

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testInvalidIdsFieldNotArrayReturns400()
    {
        $action = new BatchGetSnippetsAction($this->pdoMock);

        // --- Mock Request to have 'ids' field as a string (not an array) ---
        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['ids' => 'not-an-array']);

        // --- Mock Response for 400 error ---
        $expectedErrorPayload = ['error' => 'Missing or invalid "ids" field. It should be an array of snippet IDs.'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorPayload));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($this->responseMock);

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testEmptyIdsArrayReturnsEmptyArrayAnd200()
    {
        $action = new BatchGetSnippetsAction($this->pdoMock);

        // --- Mock Request to have 'ids' as an empty array ---
        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['ids' => []]);

        // --- Mock Response for 200 with empty array ---
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode([])); // Expect '[]'

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(200)
            ->willReturn($this->responseMock);
        
        // No PDO interaction expected in this case

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testOnlyInvalidUuidsInIdsArrayReturns400()
    {
        $action = new BatchGetSnippetsAction($this->pdoMock);

        // --- Mock Request to have 'ids' with only invalid UUIDs ---
        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['ids' => ['not-a-uuid', '12345', 'another-invalid-id']]);

        // --- Mock Response for 400 error ---
        $expectedErrorPayload = ['error' => 'No valid snippet IDs provided.'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorPayload));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($this->responseMock);
        
        // No PDO interaction expected as validation fails early

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testSuccessfulBatchRetrievalOfSnippets()
    {
        $action = new BatchGetSnippetsAction($this->pdoMock);

        $id1 = Uuid::uuid4()->toString();
        $id2 = Uuid::uuid4()->toString();
        $id3 = Uuid::uuid4()->toString(); // An ID that might not be found

        $requestedIds = [$id1, 'not-a-uuid', $id2, $id3]; // Mix of valid, invalid, and potentially non-existent
        $validRequestedIds = [$id1, $id2, $id3]; // Action filters to these

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['ids' => $requestedIds]);

        // --- Mock Database Interactions ---
        // DB returns two snippets, id3 is not found
        $dbSnippets = [
            [
                'id' => $id1, 'title' => 'Snippet One', 'description' => 'First snippet', 
                'username' => 'UserA', 'language' => 'php', 
                'tags' => '{"tagA","tag B"}', // Stored as a string like PostgreSQL array
                'code' => 'echo "Hello";', 'modification_code' => 'mod1',
                'created_at' => '2023-01-01 10:00:00', 'updated_at' => '2023-01-01 10:00:00'
            ],
            [
                'id' => $id2, 'title' => 'Snippet Two', 'description' => 'Second snippet',
                'username' => 'UserB', 'language' => 'js', 
                'tags' => '{}', // Empty tags
                'code' => 'console.log("Hi");', 'modification_code' => 'mod2',
                'created_at' => '2023-01-02 11:00:00', 'updated_at' => '2023-01-02 11:00:00'
            ],
            // id3 is intentionally omitted from DB results
        ];

        $placeholders = implode(',', array_fill(0, count($validRequestedIds), '?'));
        $expectedSql = "SELECT id, title, description, username, language, tags, code, created_at, updated_at 
                    FROM code_snippets 
                    WHERE id IN ({$placeholders})";

        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($expectedSql)
            ->willReturn($this->stmtMock);

        $this->stmtMock->expects($this->once())
            ->method('execute')
            ->with($validRequestedIds) // Expect execute with only the valid UUIDs
            ->willReturn(true);

        $this->stmtMock->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbSnippets); // Return the snippets found in DB

        // --- Expected Processed Snippets (after tag parsing and unsetting modification_code) ---
        $expectedProcessedSnippets = [
            [
                'id' => $id1, 'title' => 'Snippet One', 'description' => 'First snippet',
                'username' => 'UserA', 'language' => 'php',
                'tags' => ['tagA', 'tag B'], // Parsed tags
                'code' => 'echo "Hello";',
                'created_at' => '2023-01-01 10:00:00', 'updated_at' => '2023-01-01 10:00:00'
            ],
            [
                'id' => $id2, 'title' => 'Snippet Two', 'description' => 'Second snippet',
                'username' => 'UserB', 'language' => 'js',
                'tags' => [], // Parsed empty tags
                'code' => 'console.log("Hi");',
                'created_at' => '2023-01-02 11:00:00', 'updated_at' => '2023-01-02 11:00:00'
            ],
        ];
        
        // --- Mock Response ---
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedProcessedSnippets));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(200)
            ->willReturn($this->responseMock);

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testRetrievalWithValidIdsButNoneFoundInDbReturnsEmptyArray()
    {
        $action = new BatchGetSnippetsAction($this->pdoMock);

        $id1 = Uuid::uuid4()->toString();
        $id2 = Uuid::uuid4()->toString();
        $validRequestedIds = [$id1, $id2];

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['ids' => $validRequestedIds]);

        // --- Mock Database Interactions ---
        $placeholders = implode(',', array_fill(0, count($validRequestedIds), '?'));
        $expectedSql = "SELECT id, title, description, username, language, tags, code, created_at, updated_at 
                    FROM code_snippets 
                    WHERE id IN ({$placeholders})";

        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($expectedSql)
            ->willReturn($this->stmtMock);

        $this->stmtMock->expects($this->once())
            ->method('execute')
            ->with($validRequestedIds)
            ->willReturn(true);

        $this->stmtMock->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]); // Return empty array, as no snippets found

        // --- Expected Response (empty array) ---
        $expectedProcessedSnippets = [];
        
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedProcessedSnippets)); // Expect '[]'

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(200)
            ->willReturn($this->responseMock);

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testPdoExceptionDuringPrepareReturns500()
    {
        $action = new BatchGetSnippetsAction($this->pdoMock);

        $id1 = Uuid::uuid4()->toString();
        $validRequestedIds = [$id1];
        $exceptionMessage = "Database prepare failed";
        $pdoException = new \PDOException($exceptionMessage);

        // --- Mock Request ---
        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['ids' => $validRequestedIds]);

        // --- Mock Database Interactions to throw PDOException during prepare ---
        $placeholders = implode(',', array_fill(0, count($validRequestedIds), '?'));
        $expectedSql = "SELECT id, title, description, username, language, tags, code, created_at, updated_at 
                    FROM code_snippets 
                    WHERE id IN ({$placeholders})";

        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($expectedSql)
            ->willThrowException($pdoException);
        
        // $this->stmtMock->expects($this->never())->method('execute'); // Not strictly necessary, but good for clarity
        // $this->stmtMock->expects($this->never())->method('fetchAll');


        // --- Mock Response for 500 error ---
        $expectedErrorPayload = [
            'error' => 'Failed to retrieve snippets by batch',
            'details' => $exceptionMessage
        ];
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
        
        // Note: The action uses error_log. We are not asserting error_log output here.

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);

        $this->assertSame($this->responseMock, $returnedResponse);
    }
}
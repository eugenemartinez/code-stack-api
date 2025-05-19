<?php

declare(strict_types=1);

namespace App\Tests\Actions\Snippets;

use App\Actions\Snippets\GetRandomSnippetAction;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use PDO;
use PDOStatement;
use Ramsey\Uuid\Uuid; // For generating test UUIDs if needed

class GetRandomSnippetActionTest extends TestCase
{
    protected $pdoMock;
    protected $stmtMock;
    protected $requestMock;
    protected $responseMock;
    protected $streamMock;

    protected function setUp(): void
    {
        $this->pdoMock = $this->createMock(PDO::class);
        $this->stmtMock = $this->createMock(PDOStatement::class);
        $this->requestMock = $this->createMock(ServerRequestInterface::class);
        $this->responseMock = $this->createMock(ResponseInterface::class);
        $this->streamMock = $this->createMock(StreamInterface::class);

        $this->responseMock->method('getBody')->willReturn($this->streamMock);
        $this->responseMock->method('withHeader')->willReturn($this->responseMock);
        $this->responseMock->method('withStatus')->willReturn($this->responseMock);
    }

    // Test methods will be added here
    public function testSuccessfulRandomSnippetRetrieval()
    {
        $action = new GetRandomSnippetAction($this->pdoMock);
        $snippetId = Uuid::uuid4()->toString();

        // Note: The tag parsing in GetRandomSnippetAction is different from GetSnippetAction.
        // This test uses a tag format that the current GetRandomSnippetAction's logic can handle.
        // Example: `{"\"tag1\"","\"tag with spaces\""}` which becomes `\"tag1\",\"tag with spaces\"` after trim.
        // The regex `/"([^"]+)"/` would then extract 'tag1' and 'tag with spaces'.
        $dbSnippetData = [
            'id' => $snippetId,
            'title' => 'Random Test Snippet',
            'description' => 'A randomly selected test snippet.',
            'username' => 'RandomUser',
            'language' => 'python',
            'tags' => '{"tag1","tag with spaces"}', // Standard PostgreSQL array string
            'code' => 'print("Hello Random World!")',
            'created_at' => '2024-02-01 10:00:00',
            'updated_at' => '2024-02-01 11:00:00',
            'modification_code' => 'should_be_removed' // This should be unset by the action
        ];

        $expectedOutputSnippet = $dbSnippetData;
        unset($expectedOutputSnippet['modification_code']); // Action unsets this
        $expectedOutputSnippet['tags'] = ['tag1', 'tag with spaces']; // This should now match

        // Mock PDO query and statement execution
        // GetRandomSnippetAction uses $this->db->query() directly
        $this->pdoMock->expects($this->once())
            ->method('query')
            ->with($this->logicalAnd(
                $this->stringContains('SELECT id, title, description, username, language, tags, code, created_at, updated_at'),
                $this->stringContains('FROM code_snippets'),
                $this->stringContains('ORDER BY RANDOM()'),
                $this->stringContains('LIMIT 1')
            ))
            ->willReturn($this->stmtMock);

        $this->stmtMock->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbSnippetData);

        // Mock response body write
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedOutputSnippet));

        // Mock response headers and status
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(200)
            ->willReturn($this->responseMock);

        $returnedResponse = $action($this->requestMock, $this->responseMock);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testNoSnippetsAvailable()
    {
        $action = new GetRandomSnippetAction($this->pdoMock);

        // Mock PDO query and statement execution
        $this->pdoMock->expects($this->once())
            ->method('query')
            ->with($this->logicalAnd(
                $this->stringContains('SELECT id, title, description, username, language, tags, code, created_at, updated_at'),
                $this->stringContains('FROM code_snippets'),
                $this->stringContains('ORDER BY RANDOM()'),
                $this->stringContains('LIMIT 1')
            ))
            ->willReturn($this->stmtMock);

        $this->stmtMock->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false); // Simulate no snippets found

        // Mock response body write for the 404 error
        $expectedErrorPayload = ['error' => 'No snippets available'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorPayload));

        // Mock response headers and status for 404
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(404)
            ->willReturn($this->responseMock);
        
        // Note: GetRandomSnippetAction uses error_log, not a PSR logger mock for this case.
        // So, no logger expectation here unless we want to test error_log output,
        // which is more complex and often not done at this level of unit testing.

        $returnedResponse = $action($this->requestMock, $this->responseMock);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testPdoExceptionHandling()
    {
        $action = new GetRandomSnippetAction($this->pdoMock);

        $exceptionMessage = 'Database query failed unexpectedly';
        $pdoException = new \PDOException($exceptionMessage);

        // Mock PDO query to throw an exception
        $this->pdoMock->expects($this->once())
            ->method('query')
            ->with($this->logicalAnd( // Keep the SQL check consistent
                $this->stringContains('SELECT id, title, description, username, language, tags, code, created_at, updated_at'),
                $this->stringContains('FROM code_snippets'),
                $this->stringContains('ORDER BY RANDOM()'),
                $this->stringContains('LIMIT 1')
            ))
            ->willThrowException($pdoException);

        // Expect response->getBody()->write() to be called with error details
        // The action includes $e->getMessage() in the 'details' field.
        $expectedErrorPayload = [
            'error' => 'Failed to retrieve random snippet',
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
        
        // Note: GetRandomSnippetAction uses error_log for PDOExceptions.
        // Testing error_log directly in PHPUnit is more complex.
        // We are primarily testing that the correct 500 response is generated.
        // If you wanted to assert error_log, you might need to use a custom error handler
        // or a library that helps mock global functions, which is beyond typical unit test scope here.

        $returnedResponse = $action($this->requestMock, $this->responseMock);

        $this->assertSame($this->responseMock, $returnedResponse);
    }
}
<?php

declare(strict_types=1);

namespace App\Tests\Actions\Snippets;

use App\Actions\Snippets\GetSnippetAction;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use PDO;
use PDOStatement;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid; // For generating test UUIDs if needed

class GetSnippetActionTest extends TestCase
{
    protected $pdoMock;
    protected $stmtMock;
    protected $loggerMock;
    protected $requestMock;
    protected $responseMock;
    protected $streamMock;

    protected function setUp(): void
    {
        $this->pdoMock = $this->createMock(PDO::class);
        $this->stmtMock = $this->createMock(PDOStatement::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->requestMock = $this->createMock(ServerRequestInterface::class);
        $this->responseMock = $this->createMock(ResponseInterface::class);
        $this->streamMock = $this->createMock(StreamInterface::class);

        $this->responseMock->method('getBody')->willReturn($this->streamMock);
        // Allow withHeader and withStatus to be called any number of times and return the mock response
        $this->responseMock->method('withHeader')->willReturn($this->responseMock);
        $this->responseMock->method('withStatus')->willReturn($this->responseMock);
    }

    // We will add test methods here, for example:
    // public function testSuccessfulSnippetRetrieval() { ... }
    // public function testSnippetNotFound() { ... }
    // public function testInvalidSnippetIdFormat() { ... }

    public function testSuccessfulSnippetRetrieval()
    {
        $action = new GetSnippetAction($this->pdoMock, $this->loggerMock);
        $snippetId = Uuid::uuid4()->toString();

        $dbSnippetData = [
            'id' => $snippetId,
            'title' => 'Test Snippet Title',
            'description' => 'A great test snippet.',
            'username' => 'TestUser',
            'language' => 'php',
            'tags' => '{tag1,"tag with spaces",another-tag}', // Raw tags string from DB
            'code' => '<?php echo "Hello World!"; ?>',
            'created_at' => '2024-01-01 10:00:00',
            'updated_at' => '2024-01-01 11:00:00',
            // 'modification_code' should not be in the final output,
            // but it might be in the DB row initially. The action unsets it.
            // We'll assume it's not selected or already handled if the action doesn't select it.
        ];

        $expectedOutputSnippet = $dbSnippetData; // Start with DB data
        $expectedOutputSnippet['tags'] = ['tag1', 'tag with spaces', 'another-tag']; // Processed tags

        // Mock PDO prepare and statement execution
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('SELECT id, title, description, username, language, tags, code, created_at, updated_at'),
                $this->stringContains('FROM code_snippets'),
                $this->stringContains('WHERE id = :id')
            ))
            ->willReturn($this->stmtMock);

        $this->stmtMock->expects($this->once())
            ->method('bindParam')
            ->with(':id', $snippetId, PDO::PARAM_STR);

        $this->stmtMock->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmtMock->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbSnippetData);

        // Add logger expectation for successful retrieval
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with($this->stringContains("Snippet with ID `{$snippetId}` retrieved successfully."));

        // Mock response body write
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedOutputSnippet));

        // Mock response headers and status
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock); // Important: return the mock itself for chaining

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(200)
            ->willReturn($this->responseMock); // Important: return the mock itself for chaining

        // The GetSnippetAction expects the ID as a route argument
        $args = ['id' => $snippetId];
        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);

        // Optionally, assert that the returned response is the one we configured
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testSnippetNotFound()
    {
        $action = new GetSnippetAction($this->pdoMock, $this->loggerMock);
        $nonExistentSnippetId = Uuid::uuid4()->toString();

        // Mock PDO prepare and statement execution
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            // Using logicalAnd for robustness as before
            ->with($this->logicalAnd(
                $this->stringContains('SELECT id, title, description, username, language, tags, code, created_at, updated_at'),
                $this->stringContains('FROM code_snippets'),
                $this->stringContains('WHERE id = :id')
            ))
            ->willReturn($this->stmtMock);

        $this->stmtMock->expects($this->once())
            ->method('bindParam')
            ->with(':id', $nonExistentSnippetId, PDO::PARAM_STR);

        $this->stmtMock->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmtMock->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false); // Simulate snippet not found

        // Mock response body write for the 404 error
        $expectedErrorPayload = ['error' => 'Snippet not found'];
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
        
        // Logger should be called with a notice or info about snippet not found
        $this->loggerMock->expects($this->once())
            ->method('info') // Changed from 'notice' to 'info'
            ->with(
                $this->stringContains("Snippet with ID `{$nonExistentSnippetId}` not found for retrieval."), // Adjusted message to match action
                // $this->callback(function ($context) use ($nonExistentSnippetId) { // Context check can be simplified or removed if message is specific enough
                //     return isset($context['snippet_id']) && $context['snippet_id'] === $nonExistentSnippetId;
                // })
            );

        $args = ['id' => $nonExistentSnippetId];
        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testInvalidSnippetIdFormat()
    {
        $action = new GetSnippetAction($this->pdoMock, $this->loggerMock);
        $invalidSnippetId = 'this-is-not-a-uuid';

        // Expect logger->warning to be called
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Invalid snippet ID format for retrieval.',
                ['id_received' => $invalidSnippetId]
            );

        // Expect response->getBody()->write() to be called with error details
        $expectedErrorPayload = ['error' => 'Invalid snippet ID format'];
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
        
        // PDO should not be touched if validation fails early
        $this->pdoMock->expects($this->never())->method('prepare');

        $args = ['id' => $invalidSnippetId];
        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testPdoExceptionHandling()
    {
        $action = new GetSnippetAction($this->pdoMock, $this->loggerMock);
        $snippetId = Uuid::uuid4()->toString();

        $exceptionMessage = 'Database connection failed';
        $pdoException = new \PDOException($exceptionMessage);

        // Mock PDO prepare to throw an exception
        // This could happen at prepare(), bindParam(), execute(), or fetch()
        // Let's simulate it at prepare() for this test
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd( // Keep the SQL check consistent
                $this->stringContains('SELECT id, title, description, username, language, tags, code, created_at, updated_at'),
                $this->stringContains('FROM code_snippets'),
                $this->stringContains('WHERE id = :id')
            ))
            ->willThrowException($pdoException);
            // Alternatively, you could have stmtMock->execute() throw the exception

        // Expect logger->error to be called
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains("Database error in GetSnippetAction for ID {$snippetId}: " . $exceptionMessage),
                ['exception' => $pdoException]
            );

        // Expect response->getBody()->write() to be called with error details
        // The action currently returns a generic error message for DB issues
        $expectedErrorPayload = ['error' => 'Failed to retrieve snippet due to a database issue.'];
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
        
        $args = ['id' => $snippetId];
        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);

        $this->assertSame($this->responseMock, $returnedResponse);
    }
}
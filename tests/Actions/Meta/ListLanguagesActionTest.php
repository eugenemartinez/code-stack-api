<?php

namespace App\Tests\Actions\Meta;

use App\Actions\Meta\ListLanguagesAction;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

class ListLanguagesActionTest extends TestCase
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
    }

    public function testSuccessfulLanguageRetrieval()
    {
        $action = new ListLanguagesAction($this->pdoMock);

        $expectedLanguages = ['javascript', 'php', 'python'];
        $expectedSql = "SELECT DISTINCT language 
                    FROM code_snippets 
                    WHERE language IS NOT NULL AND language <> '' 
                    ORDER BY language ASC";

        // --- Mock Database Interactions ---
        $this->pdoMock->expects($this->once())
            ->method('query')
            ->with($expectedSql)
            ->willReturn($this->stmtMock);

        $this->stmtMock->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_COLUMN, 0)
            ->willReturn($expectedLanguages);

        // --- Mock Response ---
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedLanguages));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock); // Return self for chaining

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(200)
            ->willReturn($this->responseMock); // Return self for chaining

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testNoLanguagesFoundReturnsEmptyArray()
    {
        $action = new ListLanguagesAction($this->pdoMock);

        $expectedLanguages = []; // Empty array
        $expectedSql = "SELECT DISTINCT language 
                    FROM code_snippets 
                    WHERE language IS NOT NULL AND language <> '' 
                    ORDER BY language ASC";

        // --- Mock Database Interactions ---
        $this->pdoMock->expects($this->once())
            ->method('query')
            ->with($expectedSql)
            ->willReturn($this->stmtMock);

        $this->stmtMock->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_COLUMN, 0)
            ->willReturn($expectedLanguages); // Return empty array

        // --- Mock Response ---
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedLanguages)); // Should be '[]'

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

    public function testPdoExceptionHandling()
    {
        $action = new ListLanguagesAction($this->pdoMock);

        $exceptionMessage = "Database connection failed";
        $pdoException = new \PDOException($exceptionMessage);

        $expectedSql = "SELECT DISTINCT language 
                    FROM code_snippets 
                    WHERE language IS NOT NULL AND language <> '' 
                    ORDER BY language ASC";

        // --- Mock Database Interactions to throw PDOException ---
        $this->pdoMock->expects($this->once())
            ->method('query')
            ->with($expectedSql)
            ->willThrowException($pdoException);

        // --- Mock Response for 500 error ---
        $expectedErrorPayload = [
            'error' => 'Failed to retrieve languages',
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
        
        // Note: The action uses error_log. We are not asserting error_log output here,
        // as it's generally more involved for unit tests. We focus on the HTTP response.

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock);

        $this->assertSame($this->responseMock, $returnedResponse);
    }
}
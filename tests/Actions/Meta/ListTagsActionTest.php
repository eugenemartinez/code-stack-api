<?php

namespace App\Tests\Actions\Meta;

use App\Actions\Meta\ListTagsAction;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

class ListTagsActionTest extends TestCase
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

    public function testSuccessfulTagRetrieval()
    {
        $action = new ListTagsAction($this->pdoMock);

        $expectedTags = ['api', 'database', 'javascript', 'php'];
        // This SQL should match the one in your ListTagsAction
        $expectedSql = "SELECT DISTINCT t.tag
                    FROM code_snippets cs, UNNEST(cs.tags) AS t(tag)
                    WHERE t.tag IS NOT NULL AND t.tag <> ''
                    ORDER BY t.tag ASC";

        // --- Mock Database Interactions ---
        $this->pdoMock->expects($this->once())
            ->method('query')
            ->with($expectedSql)
            ->willReturn($this->stmtMock);

        $this->stmtMock->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_COLUMN, 0)
            ->willReturn($expectedTags);

        // --- Mock Response ---
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedTags));

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

    public function testNoTagsFoundReturnsEmptyArray()
    {
        $action = new ListTagsAction($this->pdoMock);

        $expectedTags = []; // Empty array
        $expectedSql = "SELECT DISTINCT t.tag
                    FROM code_snippets cs, UNNEST(cs.tags) AS t(tag)
                    WHERE t.tag IS NOT NULL AND t.tag <> ''
                    ORDER BY t.tag ASC";

        // --- Mock Database Interactions ---
        $this->pdoMock->expects($this->once())
            ->method('query')
            ->with($expectedSql)
            ->willReturn($this->stmtMock);

        $this->stmtMock->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_COLUMN, 0)
            ->willReturn($expectedTags); // Return empty array

        // --- Mock Response ---
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedTags)); // Should be '[]'

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
        $action = new ListTagsAction($this->pdoMock);

        $exceptionMessage = "Database query for tags failed";
        $pdoException = new \PDOException($exceptionMessage);

        $expectedSql = "SELECT DISTINCT t.tag
                    FROM code_snippets cs, UNNEST(cs.tags) AS t(tag)
                    WHERE t.tag IS NOT NULL AND t.tag <> ''
                    ORDER BY t.tag ASC";

        // --- Mock Database Interactions to throw PDOException ---
        $this->pdoMock->expects($this->once())
            ->method('query')
            ->with($expectedSql)
            ->willThrowException($pdoException);

        // --- Mock Response for 500 error ---
        $expectedErrorPayload = [
            'error' => 'Failed to retrieve tags',
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
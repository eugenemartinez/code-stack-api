<?php

namespace App\Tests\Actions\Snippets;

use App\Actions\Snippets\VerifyModificationCodeAction;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Ramsey\Uuid\Uuid;

class VerifyModificationCodeActionTest extends TestCase
{
    private $pdoMock;
    private $stmtMock; // Changed from pdoStatementMock to stmtMock to match usage
    private $requestMock;
    private $responseMock;
    private $streamMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdoMock = $this->createMock(PDO::class);
        $this->stmtMock = $this->createMock(PDOStatement::class); // Changed from pdoStatementMock
        $this->requestMock = $this->createMock(ServerRequestInterface::class);
        $this->responseMock = $this->createMock(ResponseInterface::class);
        $this->streamMock = $this->createMock(StreamInterface::class);

        $this->responseMock->method('getBody')->willReturn($this->streamMock);
        $this->responseMock->method('withHeader')->willReturn($this->responseMock);
        $this->responseMock->method('withStatus')->willReturn($this->responseMock);
    }

    public function testInvalidSnippetIdFormatReturns200WithVerifiedFalse()
    {
        $action = new VerifyModificationCodeAction($this->pdoMock);
        $invalidSnippetId = 'not-a-uuid';
        $args = ['id' => $invalidSnippetId];

        $expectedPayload = ['verified' => false, 'reason' => 'Invalid snippet ID format'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedPayload));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);
        
        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(200)
            ->willReturn($this->responseMock);

        $this->requestMock->expects($this->never())->method('getParsedBody');
        $this->pdoMock->expects($this->never())->method('prepare');

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testMissingModificationCodeReturns200WithVerifiedFalse()
    {
        $action = new VerifyModificationCodeAction($this->pdoMock);
        $validSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $validSnippetId];

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([]); 

        // Updated expected payload for missing or invalid format
        $expectedPayload = ['verified' => false, 'reason' => 'Invalid or missing modification_code format. Must be 12 alphanumeric characters.'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedPayload));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);
        
        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(200)
            ->willReturn($this->responseMock);

        $this->pdoMock->expects($this->never())->method('prepare');

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testInvalidFormatModificationCodeReturnsReason() // New test
    {
        $action = new VerifyModificationCodeAction($this->pdoMock);
        $validSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $validSnippetId];

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['modification_code' => 'short']); // Invalid format

        $expectedPayload = ['verified' => false, 'reason' => 'Invalid or missing modification_code format. Must be 12 alphanumeric characters.'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedPayload));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);
        
        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(200)
            ->willReturn($this->responseMock);

        $this->pdoMock->expects($this->never())->method('prepare');

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testSnippetNotFoundReturns200WithVerifiedFalse()
    {
        $action = new VerifyModificationCodeAction($this->pdoMock);
        $validSnippetId = Uuid::uuid4()->toString();
        $providedModificationCode = 'fixedCode123'; // Corrected: 12 char alphanumeric
        $args = ['id' => $validSnippetId];

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['modification_code' => $providedModificationCode]); // Use corrected code

        $sql = "SELECT modification_code FROM code_snippets WHERE id = :id";
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($sql)
            ->willReturn($this->stmtMock);

        $this->stmtMock->expects($this->once())
            ->method('bindParam')
            ->with(':id', $validSnippetId);
        
        $this->stmtMock->expects($this->once())
            ->method('execute');
        
        $this->stmtMock->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false); 

        $expectedPayload = ['verified' => false]; 
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedPayload));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);
        
        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(200)
            ->willReturn($this->responseMock);

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testIncorrectModificationCodeReturns200WithVerifiedFalse()
    {
        $action = new VerifyModificationCodeAction($this->pdoMock);
        $validSnippetId = Uuid::uuid4()->toString();
        $providedModificationCode = 'fixedCode123';   // Corrected: 12 char alphanumeric
        $storedModificationCode   = 'anotherCode456'; // Corrected: Different, 12 char alphanumeric
        $args = ['id' => $validSnippetId];

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['modification_code' => $providedModificationCode]); // Use corrected code

        $sql = "SELECT modification_code FROM code_snippets WHERE id = :id";
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($sql)
            ->willReturn($this->stmtMock);

        $this->stmtMock->expects($this->once())
            ->method('bindParam')
            ->with(':id', $validSnippetId);
        
        $this->stmtMock->expects($this->once())
            ->method('execute');
        
        $this->stmtMock->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['modification_code' => $storedModificationCode]); 

        $expectedPayload = ['verified' => false];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedPayload));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);
        
        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(200)
            ->willReturn($this->responseMock);

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testCorrectModificationCodeReturns200WithVerifiedTrue()
    {
        $action = new VerifyModificationCodeAction($this->pdoMock);
        $validSnippetId = Uuid::uuid4()->toString();
        $correctModificationCode = 'fixedCode123'; // Corrected: 12 char alphanumeric
        $args = ['id' => $validSnippetId];

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['modification_code' => $correctModificationCode]); // Use corrected code

        $sql = "SELECT modification_code FROM code_snippets WHERE id = :id";
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($sql)
            ->willReturn($this->stmtMock);

        $this->stmtMock->expects($this->once())
            ->method('bindParam')
            ->with(':id', $validSnippetId);
        
        $this->stmtMock->expects($this->once())
            ->method('execute');
        
        $this->stmtMock->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['modification_code' => $correctModificationCode]); 

        $expectedPayload = ['verified' => true];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedPayload));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);
        
        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(200)
            ->willReturn($this->responseMock);

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testPDOExceptionReturns500WithVerifiedFalseAndReason()
    {
        $action = new VerifyModificationCodeAction($this->pdoMock);
        $validSnippetId = Uuid::uuid4()->toString();
        $providedModificationCode = 'fixedCode123'; // Corrected: 12 char alphanumeric
        $args = ['id' => $validSnippetId];
        $exceptionMessage = "Test Database Connection Error";

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['modification_code' => $providedModificationCode]); // Use corrected code

        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with("SELECT modification_code FROM code_snippets WHERE id = :id") // Ensure SQL matches if it was changed
            ->willThrowException(new \PDOException($exceptionMessage));
        
        $expectedPayload = ['verified' => false, 'reason' => 'Database error'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedPayload));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);
        
        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(500) 
            ->willReturn($this->responseMock);

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }
}
<?php

namespace App\Tests\Actions\Snippets;

use App\Actions\Snippets\DeleteSnippetAction;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use PHPUnit\Framework\Attributes\DataProvider; // <--- ADD THIS LINE

class DeleteSnippetActionTest extends TestCase
{
    private $pdoMock;
    private $stmtMock; // Will be used for verify and delete statements
    private $requestMock;
    private $responseMock;
    private $streamMock;
    private $loggerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdoMock = $this->createMock(PDO::class);
        $this->stmtMock = $this->createMock(PDOStatement::class); // General statement mock
        $this->requestMock = $this->createMock(ServerRequestInterface::class);
        $this->responseMock = $this->createMock(ResponseInterface::class);
        $this->streamMock = $this->createMock(StreamInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->responseMock->method('getBody')->willReturn($this->streamMock);
        // Common expectation for successful chaining, can be overridden
        $this->responseMock->method('withHeader')->willReturn($this->responseMock);
        $this->responseMock->method('withStatus')->willReturn($this->responseMock);
    }

    public function testInvalidSnippetIdFormatReturns400()
    {
        $action = new DeleteSnippetAction($this->pdoMock, $this->loggerMock);
        $invalidSnippetId = 'not-a-valid-uuid';
        $args = ['id' => $invalidSnippetId];

        // --- Mock Logger for warning ---
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Invalid snippet ID format for deletion.',
                ['id_received' => $invalidSnippetId]
            );

        // --- Mock Response for 400 error ---
        $expectedErrorPayload = ['error' => 'Invalid snippet ID format.'];
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
        
        // No PDO interaction or request body parsing expected

        // --- Invoke Action ---
        // Note: DeleteSnippetAction takes $args as the third parameter
        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testMissingModificationCodeReturns400WithValidationError()
    {
        $action = new DeleteSnippetAction($this->pdoMock, $this->loggerMock);
        $validSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $validSnippetId];

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([]);

        // --- Corrected Expected Validation Error Messages ---
        $expectedValidationErrors = [
            'modification_code' => [
                // This is the precise message from the logs
                'Modification Code' => 'Modification Code must have a length of 12'
            ]
        ];
        $expectedErrorPayload = ['errors' => $expectedValidationErrors];

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                "Snippet deletion validation failed for ID: {$validSnippetId}",
                ['errors' => $expectedValidationErrors, 'payload' => ['modification_code' => null]]
            );

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
        
        // No PDO interaction expected as validation fails

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    #[DataProvider('invalidModificationCodesProvider')]
    public function testInvalidFormatModificationCodeReturns400(
        string $invalidCode,
        string $expectedMessage,
        string $testCaseName
    ) {
        $action = new DeleteSnippetAction($this->pdoMock, $this->loggerMock);
        $validSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $validSnippetId];

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['modification_code' => $invalidCode]);

        $expectedValidationErrors = [
            'modification_code' => [
                'Modification Code' => $expectedMessage
            ]
        ];
        $expectedErrorPayload = ['errors' => $expectedValidationErrors];

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                "Snippet deletion validation failed for ID: {$validSnippetId}",
                ['errors' => $expectedValidationErrors, 'payload' => ['modification_code' => $invalidCode]]
            );

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

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse, "Test case failed: " . $testCaseName);
    }

    public static function invalidModificationCodesProvider(): array
    {
        return [
            'too short' => [
                'short',
                'Modification Code must have a length of 12',
                'Code too short'
            ],
            'too long' => [
                'thiscodeiswaytoolongandinvalid', 
                'Modification Code must have a length of 12',
                'Code too long'
            ],
            'non-alphanumeric (correct length)' => [
                '12345678901!', 
                'Modification Code must contain only letters (a-z) and digits (0-9)',
                'Code non-alphanumeric, correct length'
            ],
            'non-alphanumeric (short)' => [
                'short!!!', 
                'Modification Code must have a length of 12', // <--- Update to the actual message
                'Code non-alphanumeric, short'
            ],
        ];
    }

    public function testSnippetNotFoundReturns404()
    {
        $action = new DeleteSnippetAction($this->pdoMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $validModificationCode = 'abcdef123456'; // Valid format
        $args = ['id' => $validSnippetId];

        // --- Mock Request to provide valid modification_code ---
        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['modification_code' => $validModificationCode]);

        // --- Mock Database Interaction for Verification Step ---
        $sqlVerify = "SELECT modification_code FROM code_snippets WHERE id = :id";
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($sqlVerify)
            ->willReturn($this->stmtMock);

        $this->stmtMock->expects($this->once())
            ->method('bindParam')
            ->with(':id', $validSnippetId);
        
        $this->stmtMock->expects($this->once())
            ->method('execute');

        $this->stmtMock->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false); // Simulate snippet not found

        // --- Mock Logger for info message ---
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                "Attempt to delete non-existent snippet.",
                ['id' => $validSnippetId]
            );
        
        // --- Mock Response for 404 error ---
        $expectedErrorPayload = ['error' => 'Snippet not found.'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorPayload));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(404)
            ->willReturn($this->responseMock);
        
        // No DELETE statement should be prepared or executed

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testIncorrectModificationCodeReturns403()
    {
        $action = new DeleteSnippetAction($this->pdoMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $providedModificationCode = 'abcdef123456'; // Valid format, but will be incorrect
        $storedModificationCode = 'xxxxxx654321';   // Different code stored in DB
        $args = ['id' => $validSnippetId];

        // --- Mock Request to provide valid modification_code format ---
        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['modification_code' => $providedModificationCode]);

        // --- Mock Database Interaction for Verification Step ---
        $sqlVerify = "SELECT modification_code FROM code_snippets WHERE id = :id";
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($sqlVerify)
            ->willReturn($this->stmtMock);

        $this->stmtMock->expects($this->once())
            ->method('bindParam')
            ->with(':id', $validSnippetId);
        
        $this->stmtMock->expects($this->once())
            ->method('execute');

        // Simulate snippet found, with a different modification code
        $this->stmtMock->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['modification_code' => $storedModificationCode]); 

        // --- Mock Logger for warning message ---
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                "Invalid modification code for snippet deletion.",
                ['id' => $validSnippetId, 'provided_code' => $providedModificationCode]
            );
        
        // --- Mock Response for 403 error ---
        $expectedErrorPayload = ['error' => 'Invalid modification code.'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorPayload));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(403) // Forbidden
            ->willReturn($this->responseMock);
        
        // No DELETE statement should be prepared or executed

        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testSuccessfulDeletionReturns204()
    {
        $action = new DeleteSnippetAction($this->pdoMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $correctModificationCode = 'abcdef123456'; // This code will match
        $args = ['id' => $validSnippetId];

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['modification_code' => $correctModificationCode]);

        // --- Mock Database Interaction for Verification Step ---
        $stmtVerifyMock = $this->createMock(PDOStatement::class);
        $sqlVerify = "SELECT modification_code FROM code_snippets WHERE id = :id";
        
        // --- Mock Database Interaction for Deletion Step ---
        $stmtDeleteMock = $this->createMock(PDOStatement::class);
        $sqlDelete = "DELETE FROM code_snippets WHERE id = :id AND modification_code = :modification_code";

        // --- Consolidated Mock for pdoMock->prepare() ---
        $this->pdoMock->expects($this->exactly(2)) // Expect prepare for verify AND delete
            ->method('prepare')
            ->willReturnCallback(function ($sql) use ($sqlVerify, $stmtVerifyMock, $sqlDelete, $stmtDeleteMock) {
                if ($sql === $sqlVerify) {
                    return $stmtVerifyMock;
                }
                if ($sql === $sqlDelete) {
                    return $stmtDeleteMock;
                }
                $this->fail("Unexpected SQL query passed to prepare: " . $sql); // Optional: fail if unexpected SQL
                return null; 
            });

        // --- Expectations for Verification Statement ---
        $stmtVerifyMock->expects($this->once())
            ->method('bindParam')
            ->with(':id', $validSnippetId);
        $stmtVerifyMock->expects($this->once())
            ->method('execute');
        $stmtVerifyMock->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['modification_code' => $correctModificationCode]); 

        // --- Expectations for Deletion Statement ---
        $bindParamCallCount = 0;
        $stmtDeleteMock->expects($this->exactly(2)) // Expect two calls to bindParam
            ->method('bindParam')
            ->willReturnCallback(
                function ($parameter, $value) use (&$bindParamCallCount, $validSnippetId, $correctModificationCode) {
                    if ($bindParamCallCount === 0) {
                        $this->assertSame(':id', $parameter, 'First bindParam should be :id');
                        $this->assertSame($validSnippetId, $value, 'First bindParam value for :id is incorrect');
                    } elseif ($bindParamCallCount === 1) {
                        $this->assertSame(':modification_code', $parameter, 'Second bindParam should be :modification_code');
                        $this->assertSame($correctModificationCode, $value, 'Second bindParam value for :modification_code is incorrect');
                    } else {
                        $this->fail('bindParam called more than expected');
                    }
                    $bindParamCallCount++;
                    return true; // bindParam typically returns true on success
                }
            );
        
        $stmtDeleteMock->expects($this->once())
            ->method('execute');
        $stmtDeleteMock->expects($this->once())
            ->method('rowCount')
            ->willReturn(1); // Simulate successful deletion

        // --- Mock Logger for info message ---
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with("Snippet with ID `{$validSnippetId}` was deleted successfully.");
        
        // --- Mock Response for 204 No Content ---
        $this->responseMock->expects($this->never())
            ->method('getBody'); 
        $this->responseMock->expects($this->never())
            ->method('withHeader');
        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(204)
            ->willReturn($this->responseMock);
        
        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testDeletionFailsWhenRowCountIsZeroReturns404()
    {
        $action = new DeleteSnippetAction($this->pdoMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $correctModificationCode = 'abcdef123456';
        $args = ['id' => $validSnippetId];

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['modification_code' => $correctModificationCode]);

        // --- Mock Database Interaction for Verification Step ---
        $stmtVerifyMock = $this->createMock(PDOStatement::class);
        $sqlVerify = "SELECT modification_code FROM code_snippets WHERE id = :id";
        
        // --- Mock Database Interaction for Deletion Step ---
        $stmtDeleteMock = $this->createMock(PDOStatement::class);
        $sqlDelete = "DELETE FROM code_snippets WHERE id = :id AND modification_code = :modification_code";

        // --- Consolidated Mock for pdoMock->prepare() ---
        $this->pdoMock->expects($this->exactly(2)) 
            ->method('prepare')
            ->willReturnCallback(function ($sql) use ($sqlVerify, $stmtVerifyMock, $sqlDelete, $stmtDeleteMock) {
                if ($sql === $sqlVerify) {
                    return $stmtVerifyMock;
                }
                if ($sql === $sqlDelete) {
                    return $stmtDeleteMock;
                }
                $this->fail("Unexpected SQL query passed to prepare: " . $sql);
                return null; 
            });

        // --- Expectations for Verification Statement ---
        $stmtVerifyMock->expects($this->once())
            ->method('bindParam')
            ->with(':id', $validSnippetId);
        $stmtVerifyMock->expects($this->once())
            ->method('execute');
        $stmtVerifyMock->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['modification_code' => $correctModificationCode]); 

        // --- Expectations for Deletion Statement ---
        $bindParamCallCount = 0;
        $stmtDeleteMock->expects($this->exactly(2)) 
            ->method('bindParam')
            ->willReturnCallback(
                function ($parameter, $value) use (&$bindParamCallCount, $validSnippetId, $correctModificationCode) {
                    if ($bindParamCallCount === 0) {
                        $this->assertSame(':id', $parameter);
                        $this->assertSame($validSnippetId, $value);
                    } elseif ($bindParamCallCount === 1) {
                        $this->assertSame(':modification_code', $parameter);
                        $this->assertSame($correctModificationCode, $value);
                    }
                    $bindParamCallCount++;
                    return true;
                }
            );
        
        $stmtDeleteMock->expects($this->once())
            ->method('execute');
        $stmtDeleteMock->expects($this->once())
            ->method('rowCount')
            ->willReturn(0); // Simulate deletion affecting 0 rows

        // --- Mock Logger for error message ---
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                "Failed to delete snippet after verification, or snippet already deleted.",
                ['id' => $validSnippetId, 'modification_code' => $correctModificationCode]
            );
        
        // --- Mock Response for 404 error ---
        $expectedErrorPayload = ['error' => 'Failed to delete snippet or snippet was already deleted.'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorPayload));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);
        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(404)
            ->willReturn($this->responseMock);
        
        // --- Invoke Action ---
        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testPDOExceptionDuringVerificationReturns500()
    {
        $action = new DeleteSnippetAction($this->pdoMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $correctModificationCode = 'abcdef123456';
        $args = ['id' => $validSnippetId];
        $exceptionMessage = "Test PDOException";

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['modification_code' => $correctModificationCode]);

        // --- Mock Database Interaction to throw PDOException during verification prepare ---
        $sqlVerify = "SELECT modification_code FROM code_snippets WHERE id = :id";
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($sqlVerify) // Expecting the verification query
            ->willThrowException(new \PDOException($exceptionMessage));

        // --- Mock Logger for error message ---
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                "Database error deleting snippet {$validSnippetId}: " . $exceptionMessage,
                $this->callback(function ($context) use ($exceptionMessage) { // Use callback to check exception instance
                    return isset($context['exception']) && 
                           $context['exception'] instanceof \PDOException &&
                           $context['exception']->getMessage() === $exceptionMessage;
                })
            );
        
        // --- Mock Response for 500 error ---
        $expectedErrorPayload = ['error' => 'Failed to delete snippet due to a database issue.'];
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
        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testPDOExceptionDuringDeletionPrepareReturns500()
    {
        $action = new DeleteSnippetAction($this->pdoMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $correctModificationCode = 'abcdef123456';
        $args = ['id' => $validSnippetId];
        $exceptionMessage = "Test PDOException on delete prepare";

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['modification_code' => $correctModificationCode]);

        // --- Mock Database Interaction for Verification Step (to succeed) ---
        $stmtVerifyMock = $this->createMock(PDOStatement::class);
        $sqlVerify = "SELECT modification_code FROM code_snippets WHERE id = :id";
        
        // --- Mock Database Interaction for Deletion Step (to fail at prepare) ---
        $sqlDelete = "DELETE FROM code_snippets WHERE id = :id AND modification_code = :modification_code";

        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function ($sql) use ($sqlVerify, $stmtVerifyMock, $sqlDelete, $exceptionMessage) {
                if ($sql === $sqlVerify) {
                    return $stmtVerifyMock; // Verification prepare succeeds
                }
                if ($sql === $sqlDelete) {
                    throw new \PDOException($exceptionMessage); // Deletion prepare fails
                }
                $this->fail("Unexpected SQL query passed to prepare: " . $sql);
                return null;
            });

        // --- Expectations for Verification Statement (succeeds) ---
        $stmtVerifyMock->expects($this->once())
            ->method('bindParam')
            ->with(':id', $validSnippetId);
        $stmtVerifyMock->expects($this->once())
            ->method('execute');
        $stmtVerifyMock->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['modification_code' => $correctModificationCode]); 

        // --- Mock Logger for error message ---
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                "Database error deleting snippet {$validSnippetId}: " . $exceptionMessage,
                $this->callback(function ($context) use ($exceptionMessage) {
                    return isset($context['exception']) && 
                           $context['exception'] instanceof \PDOException &&
                           $context['exception']->getMessage() === $exceptionMessage;
                })
            );
        
        // --- Mock Response for 500 error ---
        $expectedErrorPayload = ['error' => 'Failed to delete snippet due to a database issue.'];
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
        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

        public function testPDOExceptionDuringVerificationExecuteReturns500()
    {
        $action = new DeleteSnippetAction($this->pdoMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $correctModificationCode = 'abcdef123456';
        $args = ['id' => $validSnippetId];
        $exceptionMessage = "Test PDOException on verification execute";

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['modification_code' => $correctModificationCode]);

        // --- Mock Database Interaction for Verification Step (prepare succeeds, execute fails) ---
        $stmtVerifyMock = $this->createMock(PDOStatement::class);
        $sqlVerify = "SELECT modification_code FROM code_snippets WHERE id = :id";
        
        $this->pdoMock->expects($this->once()) // Only one prepare call expected
            ->method('prepare')
            ->with($sqlVerify)
            ->willReturn($stmtVerifyMock); // Verification prepare succeeds

        // --- Expectations for Verification Statement (execute throws) ---
        $stmtVerifyMock->expects($this->once())
            ->method('bindParam')
            ->with(':id', $validSnippetId);
        $stmtVerifyMock->expects($this->once())
            ->method('execute')
            ->willThrowException(new \PDOException($exceptionMessage)); // Verification execute fails
        
        // fetch should not be called
        $stmtVerifyMock->expects($this->never())->method('fetch');

        // --- Mock Logger for error message ---
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                "Database error deleting snippet {$validSnippetId}: " . $exceptionMessage,
                $this->callback(function ($context) use ($exceptionMessage) {
                    return isset($context['exception']) && 
                           $context['exception'] instanceof \PDOException &&
                           $context['exception']->getMessage() === $exceptionMessage;
                })
            );
        
        // --- Mock Response for 500 error ---
        $expectedErrorPayload = ['error' => 'Failed to delete snippet due to a database issue.'];
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
        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);

        $this->assertSame($this->responseMock, $returnedResponse);
    }

        public function testPDOExceptionDuringDeletionExecuteReturns500()
    {
        $action = new DeleteSnippetAction($this->pdoMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $correctModificationCode = 'abcdef123456';
        $args = ['id' => $validSnippetId];
        $exceptionMessage = "Test PDOException on deletion execute";

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['modification_code' => $correctModificationCode]);

        // --- Mock Database Interaction for Verification Step (succeeds) ---
        $stmtVerifyMock = $this->createMock(PDOStatement::class);
        $sqlVerify = "SELECT modification_code FROM code_snippets WHERE id = :id";
        
        // --- Mock Database Interaction for Deletion Step (prepare succeeds, execute fails) ---
        $stmtDeleteMock = $this->createMock(PDOStatement::class);
        $sqlDelete = "DELETE FROM code_snippets WHERE id = :id AND modification_code = :modification_code";

        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function ($sql) use ($sqlVerify, $stmtVerifyMock, $sqlDelete, $stmtDeleteMock) {
                if ($sql === $sqlVerify) {
                    return $stmtVerifyMock; // Verification prepare succeeds
                }
                if ($sql === $sqlDelete) {
                    return $stmtDeleteMock; // Deletion prepare succeeds
                }
                $this->fail("Unexpected SQL query passed to prepare: " . $sql);
                return null;
            });

        // --- Expectations for Verification Statement (succeeds) ---
        $stmtVerifyMock->expects($this->once())
            ->method('bindParam')
            ->with(':id', $validSnippetId);
        $stmtVerifyMock->expects($this->once())
            ->method('execute');
        $stmtVerifyMock->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['modification_code' => $correctModificationCode]); 

        // --- Expectations for Deletion Statement (execute throws) ---
        $bindParamCallCount = 0;
        $stmtDeleteMock->expects($this->exactly(2)) 
            ->method('bindParam')
            ->willReturnCallback(
                function ($parameter, $value) use (&$bindParamCallCount, $validSnippetId, $correctModificationCode) {
                    if ($bindParamCallCount === 0) {
                        $this->assertSame(':id', $parameter);
                        $this->assertSame($validSnippetId, $value);
                    } elseif ($bindParamCallCount === 1) {
                        $this->assertSame(':modification_code', $parameter);
                        $this->assertSame($correctModificationCode, $value);
                    }
                    $bindParamCallCount++;
                    return true;
                }
            );
        $stmtDeleteMock->expects($this->once())
            ->method('execute')
            ->willThrowException(new \PDOException($exceptionMessage)); // Deletion execute fails
        
        // rowCount should not be called if execute fails
        $stmtDeleteMock->expects($this->never())->method('rowCount');


        // --- Mock Logger for error message ---
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                "Database error deleting snippet {$validSnippetId}: " . $exceptionMessage,
                $this->callback(function ($context) use ($exceptionMessage) {
                    return isset($context['exception']) && 
                           $context['exception'] instanceof \PDOException &&
                           $context['exception']->getMessage() === $exceptionMessage;
                })
            );
        
        // --- Mock Response for 500 error ---
        $expectedErrorPayload = ['error' => 'Failed to delete snippet due to a database issue.'];
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
        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);

        $this->assertSame($this->responseMock, $returnedResponse);
    }
}
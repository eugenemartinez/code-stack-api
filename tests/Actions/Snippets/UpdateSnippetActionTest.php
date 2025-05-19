<?php

namespace App\Tests\Actions\Snippets;

use App\Actions\Snippets\UpdateSnippetAction;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use HTMLPurifier;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid; // For generating valid UUIDs in tests

class UpdateSnippetActionTest extends TestCase
{
    private $pdoMock;
    private $stmtMock; // General purpose statement mock
    private $requestMock;
    private $responseMock;
    private $streamMock;
    private $htmlPurifierMock;
    private $loggerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdoMock = $this->createMock(PDO::class);
        $this->stmtMock = $this->createMock(PDOStatement::class);
        $this->requestMock = $this->createMock(ServerRequestInterface::class);
        $this->responseMock = $this->createMock(ResponseInterface::class);
        $this->streamMock = $this->createMock(StreamInterface::class);
        $this->htmlPurifierMock = $this->createMock(HTMLPurifier::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->responseMock->method('getBody')->willReturn($this->streamMock);
        $this->responseMock->method('withHeader')->willReturn($this->responseMock); // Chainable
        $this->responseMock->method('withStatus')->willReturn($this->responseMock);   // Chainable
    }

    public function testInvalidSnippetIdFormatReturns400()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        $invalidSnippetId = 'not-a-uuid';
        $args = ['id' => $invalidSnippetId];

        // Mock logger
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Update attempt with invalid snippet ID format.', ['id' => $invalidSnippetId]);

        // Mock response
        $expectedErrorPayload = ['error' => 'Invalid snippet ID format'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorPayload));
        
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock); // Important for chaining

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($this->responseMock); // Important for chaining

        // No request body parsing or other DB interaction expected
        $this->requestMock->expects($this->never())->method('getParsedBody');
        $this->pdoMock->expects($this->never())->method('prepare');

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testDirectTitleValidationFailsForTooLongTitleReturns400()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $validSnippetId];
        
        $longTitle = str_repeat('a', 256); 
        $requestBody = ['title' => $longTitle, 'modification_code' => 'irrelevant123']; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Update this line to match the actual message from Respect\Validation's getMessage()
        $expectedValidationMessage = 'All of the required rules must pass for "' . $longTitle . '"';

        $loggerInvocationCount = 0;
        $this->loggerMock->expects($this->exactly(2)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$loggerInvocationCount, $longTitle, $expectedValidationMessage) {
                    if ($loggerInvocationCount === 0) {
                        $this->assertSame("DIRECT TEST: Title value", $message);
                        $this->assertSame(['title' => $longTitle, 'length_php' => strlen($longTitle)], $context);
                    } elseif ($loggerInvocationCount === 1) {
                        $this->assertSame("DIRECT TEST: Title length validation FAILED AS EXPECTED", $message);
                        // This assertion was failing:
                        $this->assertSame(['main_message' => $expectedValidationMessage, 'details' => [$expectedValidationMessage]], $context);
                    } else {
                        $this->fail("Logger debug method called more than expected.");
                    }
                    $loggerInvocationCount++;
                    return null; 
                }
            );

        $expectedErrorPayload = [
            'error' => 'DIRECT TEST: Title too long',
            'details' => [$expectedValidationMessage] // This also needs to use the updated $expectedValidationMessage
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
            ->with(400)
            ->willReturn($this->responseMock);

        $this->pdoMock->expects($this->never())->method('prepare');

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testDirectTitleValidationFailsForEmptyTitleReturns400()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $validSnippetId];
        
        $emptyTitle = ''; // Title is empty
        $requestBody = ['title' => $emptyTitle, 'modification_code' => 'irrelevant123']; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Expected message from Respect\Validation for an empty title failing length(1, 255).
        // Based on previous experience, getMessage() might return a generic wrapper.
        $expectedValidationMessage = 'All of the required rules must pass for "' . $emptyTitle . '"';


        $loggerInvocationCount = 0;
        $this->loggerMock->expects($this->exactly(2)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$loggerInvocationCount, $emptyTitle, $expectedValidationMessage) {
                    if ($loggerInvocationCount === 0) {
                        $this->assertSame("DIRECT TEST: Title value", $message);
                        $this->assertSame(['title' => $emptyTitle, 'length_php' => strlen($emptyTitle)], $context);
                    } elseif ($loggerInvocationCount === 1) {
                        $this->assertSame("DIRECT TEST: Title length validation FAILED AS EXPECTED", $message);
                        $this->assertSame(['main_message' => $expectedValidationMessage, 'details' => [$expectedValidationMessage]], $context);
                    } else {
                        $this->fail("Logger debug method called more than expected.");
                    }
                    $loggerInvocationCount++;
                    return null; 
                }
            );

        $expectedErrorPayload = [
            'error' => 'DIRECT TEST: Title too long', // This is the current hardcoded error message
            'details' => [$expectedValidationMessage]
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
            ->with(400)
            ->willReturn($this->responseMock);

        $this->pdoMock->expects($this->never())->method('prepare');

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testDirectTitleValidationPassesAndMainValidationFailsForModificationCode()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $validSnippetId];
        
        $validTitle = "A Perfectly Valid Title";
        $invalidModificationCode = "short123"; // Invalid length (not 12)
        $requestBody = ['title' => $validTitle, 'modification_code' => $invalidModificationCode]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger expectations
        $debugCallCount = 0;
        $this->loggerMock->expects($this->exactly(3)) // Expect 3 debug calls
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $validTitle) {
                    if ($debugCallCount === 0) { // First debug call
                        $this->assertSame("DIRECT TEST: Title value", $message);
                        $this->assertSame(['title' => $validTitle, 'length_php' => strlen($validTitle)], $context);
                    } elseif ($debugCallCount === 1) { // Second debug call
                        $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message);
                        $this->assertEmpty($context); 
                    } elseif ($debugCallCount === 2) { // Third debug call (temporary log for title in main validation)
                        $this->assertSame("Validating title", $message); // From the temporary debug log
                        $this->assertSame(['value' => $validTitle, 'length' => strlen($validTitle)], $context);
                    } else {
                        $this->fail("Logger debug method called more than expected.");
                    }
                    $debugCallCount++;
                    return null; 
                }
            );

        // Expected error message for modification_code from main validation.
        // TRY THIS VERY SPECIFIC VERSION:
        $expectedModificationCodeError = 'Modification Code must have a length of 12';

        $this->loggerMock->expects($this->once()) // Expect 1 warning call
            ->method('warning')
            ->with(
                $this->stringContains('Snippet update validation failed.'),
                $this->callback(function ($context) use ($validSnippetId, $requestBody, $expectedModificationCodeError) {
                    $this->assertSame($validSnippetId, $context['id']);
                    $this->assertEquals($requestBody, $context['payload']); 
                    $this->assertArrayHasKey('modification_code', $context['errors']);
                    // This is the assertion that keeps failing.
                    // Let's see if the actual error array ($context['errors']['modification_code'])
                    // contains 'Modification Code must have a length of 12'.
                    $this->assertContains(
                        $expectedModificationCodeError,
                        $context['errors']['modification_code'] 
                    );
                    return true; 
                })
            );
        
        $expectedErrorPayload = [
            'errors' => [
                'modification_code' => [$expectedModificationCodeError]
            ]
        ];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($jsonString) use ($expectedModificationCodeError) {
                $data = json_decode($jsonString, true);
                return isset($data['errors']['modification_code']) &&
                       is_array($data['errors']['modification_code']) &&
                       in_array($expectedModificationCodeError, $data['errors']['modification_code']);
            }));
        
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($this->responseMock);

        $this->pdoMock->expects($this->never())->method('prepare');

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testMainValidationFailsForLanguageTooLong()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $validSnippetId];
        
        $validTitle = "Another Valid Title";
        $validModificationCode = "validModCode"; // 12 chars
        $tooLongLanguage = str_repeat('L', 51); // 51 chars, max is 50

        $requestBody = [
            'title' => $validTitle, 
            'modification_code' => $validModificationCode,
            'language' => $tooLongLanguage 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger expectations:
        // 1. "DIRECT TEST: Title value" (debug)
        // 2. "DIRECT TEST: Title length validation PASSED..." (debug)
        // 3. "Validating title" (temporary debug from main validation loop)
        // 4. "Snippet update validation failed." (warning)
        $debugCallCount = 0;
        $this->loggerMock->expects($this->exactly(3)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $validTitle) {
                    if ($debugCallCount === 0) { 
                        $this->assertSame("DIRECT TEST: Title value", $message);
                        $this->assertSame(['title' => $validTitle, 'length_php' => strlen($validTitle)], $context);
                    } elseif ($debugCallCount === 1) { 
                        $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message);
                        $this->assertEmpty($context); 
                    } elseif ($debugCallCount === 2) { 
                        $this->assertSame("Validating title", $message); 
                        $this->assertSame(['value' => $validTitle, 'length' => strlen($validTitle)], $context);
                    } else {
                        $this->fail("Logger debug method called more than expected.");
                    }
                    $debugCallCount++;
                    return null; 
                }
            );

        // Expected error message for language. For length(min, max) it's usually "between min and max".
        $expectedLanguageError = 'Language must have a length between 1 and 50';

        $this->loggerMock->expects($this->once()) 
            ->method('warning')
            ->with(
                $this->stringContains('Snippet update validation failed.'),
                $this->callback(function ($context) use ($validSnippetId, $requestBody, $expectedLanguageError) {
                    $this->assertSame($validSnippetId, $context['id']);
                    $this->assertEquals($requestBody, $context['payload']); 
                    $this->assertArrayHasKey('language', $context['errors']);
                    $this->assertContains(
                        $expectedLanguageError,
                        $context['errors']['language']
                    );
                    return true; 
                })
            );
        
        $expectedErrorPayload = [
            'errors' => [
                'language' => [$expectedLanguageError]
            ]
        ];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($jsonString) use ($expectedLanguageError) {
                $data = json_decode($jsonString, true);
                return isset($data['errors']['language']) &&
                       is_array($data['errors']['language']) &&
                       in_array($expectedLanguageError, $data['errors']['language']);
            }));
        
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($this->responseMock);

        $this->pdoMock->expects($this->never())->method('prepare');

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testMainValidationFailsForDescriptionTooLong()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $validSnippetId];
        
        $validTitle = "Title For Description Test";
        $validModificationCode = "descModCode1"; // 12 chars
        $tooLongDescription = str_repeat('D', 1001); // 1001 chars, max is 1000

        $requestBody = [
            'title' => $validTitle, 
            'modification_code' => $validModificationCode,
            'description' => $tooLongDescription 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger expectations:
        // 1. "DIRECT TEST: Title value" (debug)
        // 2. "DIRECT TEST: Title length validation PASSED..." (debug)
        // 3. "Validating title" (temporary debug from main validation loop)
        // 4. "Snippet update validation failed." (warning)
        $debugCallCount = 0;
        $this->loggerMock->expects($this->exactly(3)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $validTitle) {
                    if ($debugCallCount === 0) { 
                        $this->assertSame("DIRECT TEST: Title value", $message);
                        $this->assertSame(['title' => $validTitle, 'length_php' => strlen($validTitle)], $context);
                    } elseif ($debugCallCount === 1) { 
                        $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message);
                        $this->assertEmpty($context); 
                    } elseif ($debugCallCount === 2) { 
                        $this->assertSame("Validating title", $message); 
                        $this->assertSame(['value' => $validTitle, 'length' => strlen($validTitle)], $context);
                    } else {
                        $this->fail("Logger debug method called more than expected.");
                    }
                    $debugCallCount++;
                    return null; 
                }
            );

        // Expected error message for description.
        $expectedDescriptionError = 'Description must have a length lower than or equal to 1000';

        $this->loggerMock->expects($this->once()) 
            ->method('warning')
            ->with(
                $this->stringContains('Snippet update validation failed.'),
                $this->callback(function ($context) use ($validSnippetId, $requestBody, $expectedDescriptionError) {
                    $this->assertSame($validSnippetId, $context['id']);
                    $this->assertEquals($requestBody, $context['payload']); 
                    $this->assertArrayHasKey('description', $context['errors']);

                    $this->assertContains(
                        $expectedDescriptionError,
                        $context['errors']['description']
                    );
                    return true; 
                })
            );
        
        $expectedErrorPayload = [
            'errors' => [
                'description' => [$expectedDescriptionError]
            ]
        ];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($jsonString) use ($expectedDescriptionError) {
                $data = json_decode($jsonString, true);
                return isset($data['errors']['description']) &&
                       is_array($data['errors']['description']) &&
                       in_array($expectedDescriptionError, $data['errors']['description']);
            }));
        
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($this->responseMock);

        $this->pdoMock->expects($this->never())->method('prepare');

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testMainValidationFailsForEmptyCode()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $validSnippetId];
        
        $validTitle = "Title For Code Test";
        $validModificationCode = "codeModCode1"; // 12 chars
        $emptyCode = ""; // Empty code string

        $requestBody = [
            'title' => $validTitle, 
            'modification_code' => $validModificationCode,
            'code' => $emptyCode 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger expectations:
        // 1. "DIRECT TEST: Title value" (debug)
        // 2. "DIRECT TEST: Title length validation PASSED..." (debug)
        // 3. "Validating title" (temporary debug from main validation loop)
        // 4. "Snippet update validation failed." (warning)
        $debugCallCount = 0;
        $this->loggerMock->expects($this->exactly(3)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $validTitle) {
                    if ($debugCallCount === 0) { 
                        $this->assertSame("DIRECT TEST: Title value", $message);
                        $this->assertSame(['title' => $validTitle, 'length_php' => strlen($validTitle)], $context);
                    } elseif ($debugCallCount === 1) { 
                        $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message);
                        $this->assertEmpty($context); 
                    } elseif ($debugCallCount === 2) { 
                        $this->assertSame("Validating title", $message); 
                        $this->assertSame(['value' => $validTitle, 'length' => strlen($validTitle)], $context);
                    } else {
                        $this->fail("Logger debug method called more than expected.");
                    }
                    $debugCallCount++;
                    return null; 
                }
            );

        // Expected error message for code when it's empty.
        $expectedCodeError = 'Code must not be empty';

        $this->loggerMock->expects($this->once()) 
            ->method('warning')
            ->with(
                $this->stringContains('Snippet update validation failed.'),
                $this->callback(function ($context) use ($validSnippetId, $requestBody, $expectedCodeError) {
                    $this->assertSame($validSnippetId, $context['id']);
                    $this->assertEquals($requestBody, $context['payload']); 
                    $this->assertArrayHasKey('code', $context['errors']);
                    $this->assertContains(
                        $expectedCodeError,
                        $context['errors']['code']
                    );
                    return true; 
                })
            );
        
        $expectedErrorPayload = [
            'errors' => [
                'code' => [$expectedCodeError]
            ]
        ];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($jsonString) use ($expectedCodeError) {
                $data = json_decode($jsonString, true);
                return isset($data['errors']['code']) &&
                       is_array($data['errors']['code']) &&
                       in_array($expectedCodeError, $data['errors']['code']);
            }));
        
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($this->responseMock);

        $this->pdoMock->expects($this->never())->method('prepare');

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testMainValidationFailsForTagsNotAnArray()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $validSnippetId];
        
        $validTitle = "Title For Tags Test";
        $validModificationCode = "tagsModCode1"; // 12 chars
        $invalidTags = "not-an-array"; // Tags should be an array

        $requestBody = [
            'title' => $validTitle, 
            'modification_code' => $validModificationCode,
            'tags' => $invalidTags 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger expectations (3 debug calls for title, 1 warning for validation failure)
        $debugCallCount = 0;
        $this->loggerMock->expects($this->exactly(3)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $validTitle) {
                    if ($debugCallCount === 0) { 
                        $this->assertSame("DIRECT TEST: Title value", $message);
                        $this->assertSame(['title' => $validTitle, 'length_php' => strlen($validTitle)], $context);
                    } elseif ($debugCallCount === 1) { 
                        $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message);
                        $this->assertEmpty($context); 
                    } elseif ($debugCallCount === 2) { 
                        $this->assertSame("Validating title", $message); 
                        $this->assertSame(['value' => $validTitle, 'length' => strlen($validTitle)], $context);
                    } else {
                        $this->fail("Logger debug method called more than expected.");
                    }
                    $debugCallCount++;
                    return null; 
                }
            );

        // Expected error message for tags when it's not an array.
        // The action will now convert false to this string.
        $expectedTagsErrorString = 'Tags must be of type array'; 

        $this->loggerMock->expects($this->once()) 
            ->method('warning')
            ->with(
                $this->stringContains('Snippet update validation failed.'),
                $this->callback(function ($context) use ($validSnippetId, $requestBody, $expectedTagsErrorString) {
                    $this->assertSame($validSnippetId, $context['id']);
                    $this->assertEquals($requestBody, $context['payload']); 
                    $this->assertArrayHasKey('tags', $context['errors']);
                    // The error structure is ['Tags' => 'message']
                    $this->assertArrayHasKey('Tags', $context['errors']['tags']);
                    $this->assertSame($expectedTagsErrorString, $context['errors']['tags']['Tags']);
                    return true; 
                })
            );
        
        // The expected JSON error payload structure
        $expectedErrorPayload = [
            'errors' => [
                'tags' => [
                    'Tags' => $expectedTagsErrorString 
                ]
            ]
        ];
        $this->streamMock->expects($this->once())
            ->method('write')
            // Use a direct comparison with the expected JSON string
            ->with(json_encode($expectedErrorPayload));
        
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($this->responseMock);

        $this->pdoMock->expects($this->never())->method('prepare');

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testMainValidationFailsForTagsWithNonStringElement()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $validSnippetId];
        
        $validTitle = "Title For Invalid Tag Element Test";
        $validModificationCode = "tagElModCode"; // 12 chars
        
        // This is the value that causes the 'stringType' rule within 'each' to fail
        $failingElementValue = 123; 
        $tagsWithInvalidElement = ["validTag", $failingElementValue]; 

        $requestBody = [
            'title' => $validTitle, 
            'modification_code' => $validModificationCode,
            'tags' => $tagsWithInvalidElement 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger expectations
        $debugCallCount = 0;
        $this->loggerMock->expects($this->exactly(3)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $validTitle) {
                    if ($debugCallCount === 0) { 
                        $this->assertSame("DIRECT TEST: Title value", $message);
                        $this->assertSame(['title' => $validTitle, 'length_php' => strlen($validTitle)], $context);
                    } elseif ($debugCallCount === 1) { 
                        $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message);
                        $this->assertEmpty($context); 
                    } elseif ($debugCallCount === 2) { 
                        $this->assertSame("Validating title", $message); 
                        $this->assertSame(['value' => $validTitle, 'length' => strlen($validTitle)], $context);
                    } else {
                        $this->fail("Logger debug method called more than expected.");
                    }
                    $debugCallCount++;
                    return null; 
                }
            );

        // Based on the var_dump, the error message is for the 'Tags' field itself,
        // and it's a generic message incorporating the failing element's value.
        $expectedTagsErrorMessage = 'These rules must pass for ' . $failingElementValue;

        $this->loggerMock->expects($this->once()) 
            ->method('warning')
            ->with(
                $this->stringContains('Snippet update validation failed.'),
                $this->callback(function ($context) use ($validSnippetId, $requestBody, $expectedTagsErrorMessage) {
                    $this->assertSame($validSnippetId, $context['id']);
                    $this->assertEquals($requestBody, $context['payload']); 
                    
                    // Check that 'tags' error key exists
                    $this->assertArrayHasKey('tags', $context['errors']);
                    $this->assertIsArray($context['errors']['tags']);

                    // Based on var_dump: $context['errors']['tags'] is ['Tags' => 'message']
                    $this->assertArrayHasKey('Tags', $context['errors']['tags']);
                    $this->assertSame($expectedTagsErrorMessage, $context['errors']['tags']['Tags']);
                    return true; 
                })
            );
        
        // The expected JSON error payload structure based on the var_dump
        $expectedErrorPayload = [
            'errors' => [
                'tags' => [
                    'Tags' => $expectedTagsErrorMessage 
                ]
            ]
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
            ->with(400)
            ->willReturn($this->responseMock);

        $this->pdoMock->expects($this->never())->method('prepare');

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testMainValidationFailsForTagsWithEmptyStringElement()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $validSnippetId];
        
        $validTitle = "Title For Empty Tag Element Test";
        $validModificationCode = "tagEmpModCod"; // 12 chars
        
        $failingElementValue = ""; // Empty string tag
        $tagsWithEmptyElement = ["validTag", $failingElementValue, "anotherValid"];

        $requestBody = [
            'title' => $validTitle, 
            'modification_code' => $validModificationCode,
            'tags' => $tagsWithEmptyElement 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger expectations
        $debugCallCount = 0;
        $this->loggerMock->expects($this->exactly(3)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $validTitle) {
                    if ($debugCallCount === 0) { 
                        $this->assertSame("DIRECT TEST: Title value", $message);
                        $this->assertSame(['title' => $validTitle, 'length_php' => strlen($validTitle)], $context);
                    } elseif ($debugCallCount === 1) { 
                        $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message);
                        $this->assertEmpty($context); 
                    } elseif ($debugCallCount === 2) { 
                        $this->assertSame("Validating title", $message); 
                        $this->assertSame(['value' => $validTitle, 'length' => strlen($validTitle)], $context);
                    } else {
                        $this->fail("Logger debug method called more than expected.");
                    }
                    $debugCallCount++;
                    return null; 
                }
            );

        // Based on the failure log, Respect\Validation represents the empty string as "" in the message.
        $expectedTagsErrorMessage = 'These rules must pass for "' . $failingElementValue . '"';


        $this->loggerMock->expects($this->once()) 
            ->method('warning')
            ->with(
                $this->stringContains('Snippet update validation failed.'),
                $this->callback(function ($context) use ($validSnippetId, $requestBody, $expectedTagsErrorMessage) {
                    $this->assertSame($validSnippetId, $context['id']);
                    $this->assertEquals($requestBody, $context['payload']); 
                    
                    $this->assertArrayHasKey('tags', $context['errors']);
                    $this->assertIsArray($context['errors']['tags']);
                    $this->assertArrayHasKey('Tags', $context['errors']['tags']);
                    $this->assertSame($expectedTagsErrorMessage, $context['errors']['tags']['Tags']);
                    return true; 
                })
            );
        
        $expectedErrorPayload = [
            'errors' => [
                'tags' => [
                    'Tags' => $expectedTagsErrorMessage 
                ]
            ]
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
            ->with(400)
            ->willReturn($this->responseMock);

        $this->pdoMock->expects($this->never())->method('prepare');

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testMainValidationFailsForMissingModificationCode()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $validSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $validSnippetId];
        
        $validTitle = "Title For Missing ModCode Test";
        // Request body is missing 'modification_code'
        $requestBody = [
            'title' => $validTitle, 
            'language' => 'php' 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger expectations
        $debugCallCount = 0;
        $this->loggerMock->expects($this->exactly(3)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $validTitle) {
                    if ($debugCallCount === 0) { 
                        $this->assertSame("DIRECT TEST: Title value", $message);
                        $this->assertSame(['title' => $validTitle, 'length_php' => strlen($validTitle)], $context);
                    } elseif ($debugCallCount === 1) { 
                        $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message);
                        $this->assertEmpty($context); 
                    } elseif ($debugCallCount === 2) { 
                        $this->assertSame("Validating title", $message); 
                        $this->assertSame(['value' => $validTitle, 'length' => strlen($validTitle)], $context);
                    } else {
                        $this->fail("Logger debug method called more than expected.");
                    }
                    $debugCallCount++;
                    return null; 
                }
            );

        // Expected error message for modification_code when it's missing.
        // Based on var_dump, the length rule's message is reported.
        $expectedModCodeError = 'Modification Code must have a length of 12';

        $this->loggerMock->expects($this->once()) 
            ->method('warning')
            ->with(
                $this->stringContains('Snippet update validation failed.'),
                $this->callback(function ($context) use ($validSnippetId, $requestBody, $expectedModCodeError) {
                    $this->assertSame($validSnippetId, $context['id']);
                    $this->assertEquals($requestBody, $context['payload']); 
                    
                    $this->assertArrayHasKey('modification_code', $context['errors']);
                    $this->assertIsArray($context['errors']['modification_code']);
                    // Based on var_dump: $context['errors']['modification_code'] is ['Modification Code' => 'message']
                    $this->assertArrayHasKey('Modification Code', $context['errors']['modification_code']);
                    $this->assertSame($expectedModCodeError, $context['errors']['modification_code']['Modification Code']);
                    return true; 
                })
            );
        
        $expectedErrorPayload = [
            'errors' => [
                'modification_code' => [
                    'Modification Code' => $expectedModCodeError 
                ]
            ]
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
            ->with(400)
            ->willReturn($this->responseMock);

        $this->pdoMock->expects($this->never())->method('prepare');

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testUpdateReturns404IfSnippetNotFound()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $nonExistentSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $nonExistentSnippetId];
        
        // A minimal valid request body that would pass main validation
        $requestBody = [
            'title' => 'Valid Title for 404 Test',
            'modification_code' => 'testModCodeA' // Exactly 12 characters
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger expectations for the "direct title validation" part
        $debugCallCount = 0;
        $this->loggerMock->expects($this->exactly(3)) // Direct title test (2) + main title validation (1)
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $requestBody) {
                    if ($debugCallCount === 0) { 
                        $this->assertSame("DIRECT TEST: Title value", $message);
                    } elseif ($debugCallCount === 1) { 
                        $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message);
                    } elseif ($debugCallCount === 2) { 
                        $this->assertSame("Validating title", $message); 
                    }
                    $debugCallCount++;
                    return null; 
                }
            );
        
        // --- Database interaction for modification code verification ---
        // 1. Prepare the SELECT query for modification_code
        $this->pdoMock->expects($this->once()) // Expect prepare to be called once for the SELECT
            ->method('prepare')
            ->with($this->stringContains("SELECT modification_code FROM code_snippets WHERE id = :id"))
            ->willReturn($this->stmtMock);

        // 2. BindParam for :id
        $this->stmtMock->expects($this->once())
            ->method('bindParam')
            ->with(':id', $nonExistentSnippetId)
            ->willReturn(true);

        // 3. Execute the SELECT query
        $this->stmtMock->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        // 4. Fetch should return false (snippet not found)
        $this->stmtMock->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false); // This simulates snippet not found

        // Logger expectation for snippet not found
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('Snippet not found for update.', ['id' => $nonExistentSnippetId]);

        // Expected 404 response
        $expectedErrorPayload = ['error' => 'Snippet not found'];
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

        // No further 'prepare' calls (e.g., for the UPDATE statement)
        // The first prepare was already counted above.

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testUpdateReturns403IfModificationCodeMismatch()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];
        
        $correctDbModCode = 'dbModCode123'; // 12 chars, stored in DB
        $incorrectUserModCode = 'userModCodeX'; // 12 chars, provided by user, but different

        $requestBody = [
            'title' => 'Valid Title for 403 Test',
            'modification_code' => $incorrectUserModCode 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger expectations for the "direct title validation" part
        $debugCallCount = 0;
        $this->loggerMock->expects($this->exactly(3)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $requestBody) {
                    if ($debugCallCount === 0) { 
                        $this->assertSame("DIRECT TEST: Title value", $message);
                    } elseif ($debugCallCount === 1) { 
                        $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message);
                    } elseif ($debugCallCount === 2) { 
                        $this->assertSame("Validating title", $message); 
                    }
                    $debugCallCount++;
                    return null; 
                }
            );
        
        // --- Database interaction for modification code verification ---
        // 1. Prepare the SELECT query
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("SELECT modification_code FROM code_snippets WHERE id = :id"))
            ->willReturn($this->stmtMock);

        // 2. BindParam for :id
        $this->stmtMock->expects($this->once())
            ->method('bindParam')
            ->with(':id', $existingSnippetId)
            ->willReturn(true);

        // 3. Execute the SELECT query
        $this->stmtMock->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        // 4. Fetch returns the snippet with the correct DB modification code
        $this->stmtMock->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['modification_code' => $correctDbModCode]); 

        // Logger expectation for invalid modification code
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Invalid modification_code for update.', 
                ['id' => $existingSnippetId, 'provided_code' => $incorrectUserModCode]
            );

        // Expected 403 response
        $expectedErrorPayload = ['error' => 'Invalid modification_code'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorPayload));
        
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(403)
            ->willReturn($this->responseMock);

        // No further 'prepare' calls (e.g., for the UPDATE statement)

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testSuccessfulUpdateOfTitle()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];
        
        $dbModificationCode = 'correctMod12'; // 12 chars
        $newTitle = "Successfully Updated Title";
        $originalDescription = "Original Description";
        $originalLanguage = "php";
        $originalCode = "echo 'hello';";
        $originalUsername = "testuser";
        $originalCreatedAt = gmdate('Y-m-d H:i:s', time() - 3600);

        $requestBody = [
            'title' => $newTitle,
            'modification_code' => $dbModificationCode 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger expectations
        $debugCallCount = 0;
        $this->loggerMock->expects($this->exactly(3)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $newTitle) {
                    if ($debugCallCount === 0) { 
                        $this->assertSame("DIRECT TEST: Title value", $message);
                        $this->assertSame(['title' => $newTitle, 'length_php' => strlen($newTitle)], $context);
                    } elseif ($debugCallCount === 1) { 
                        $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message);
                        $this->assertEmpty($context);
                    } elseif ($debugCallCount === 2) { 
                        $this->assertSame("Validating title", $message); 
                        $this->assertSame(['value' => $newTitle, 'length' => strlen($newTitle)], $context);
                    }
                    $debugCallCount++;
                    return null; 
                }
            );
        
        // --- Mocking Database Operations ---
        $stmtVerifyModCode = $this->createMock(PDOStatement::class);
        $stmtUpdate = $this->createMock(PDOStatement::class);
        $stmtFetchFinal = $this->createMock(PDOStatement::class);

        // Mocking pdo->prepare() calls using a callback to manage sequence and arguments
        $prepareCallMatcher = new class($this) extends \PHPUnit\Framework\Constraint\Constraint {
            private $testCase;
            private $callCount = 0;
            public function __construct($testCase) { $this->testCase = $testCase; }
            protected function matches($other): bool {
                switch ($this->callCount) {
                    case 0: $this->testCase->assertStringContainsString("SELECT modification_code FROM code_snippets WHERE id = :id", $other); break;
                    case 1: $this->testCase->assertStringContainsString("UPDATE code_snippets SET title = :title, updated_at = :updated_at WHERE id = :id", $other); break;
                    case 2: // <<< CHANGE IS HERE
                        $this->testCase->assertStringContainsString("SELECT id, title, description, username, language, tags, code, created_at, updated_at FROM code_snippets WHERE id = :id", $other); 
                        break;
                    default: $this->testCase->fail('pdoMock->prepare called too many times in custom matcher.');
                }
                $this->callCount++;
                return true;
            }
            public function toString(): string { return 'matches expected SQL for prepare call ' . $this->callCount; }
        };

        $this->pdoMock->expects($this->exactly(3)) 
            ->method('prepare')
            ->with($prepareCallMatcher) 
            ->willReturnOnConsecutiveCalls( 
                $stmtVerifyModCode,
                $stmtUpdate,
                $stmtFetchFinal
            );

        // 1. Modification Code Verification
        $stmtVerifyModCode->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('execute')->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['modification_code' => $dbModificationCode]);

        // 2. Database Update (on $stmtUpdate)
        $stmtUpdate->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) use ($existingSnippetId, $newTitle) {
                $this->assertIsArray($params);
                $this->assertCount(3, $params, "Expected 3 parameters for execute: :id, :title, :updated_at"); // id, title, updated_at
                
                $this->assertArrayHasKey(':id', $params);
                $this->assertSame($existingSnippetId, $params[':id']);
                
                $this->assertArrayHasKey(':title', $params);
                $this->assertSame(strip_tags(trim($newTitle)), $params[':title']);
                
                $this->assertArrayHasKey(':updated_at', $params);
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $params[':updated_at']);
                
                return true;
            }))
            ->willReturn(true);

        // 3. Fetch of Updated Snippet (on $stmtFetchFinal)
        $stmtFetchFinal->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtFetchFinal->expects($this->once())->method('execute')->willReturn(true);
        
        $expectedUpdatedAt = gmdate('Y-m-d H:i:s'); 
        $updatedSnippetData = [ // <<< CHANGE IS HERE
            'id' => $existingSnippetId,
            'title' => strip_tags(trim($newTitle)), 
            'description' => $originalDescription,
            'username' => $originalUsername,
            'language' => $originalLanguage,
            'tags' => null, // Action will convert this to [] in the response
            'code' => $originalCode,
            'created_at' => $originalCreatedAt,
            'updated_at' => $expectedUpdatedAt 
        ];
        $stmtFetchFinal->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($updatedSnippetData);

        // Logger expectation for successful update
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                "Snippet `{$existingSnippetId}` was updated.",
                ['id' => $existingSnippetId, 'updated_fields' => ['title']]
            );

        // Expected 200 response
        $responseDataForClient = $updatedSnippetData;
        // unset($responseDataForClient['tags_json']); // This line is no longer needed as 'tags_json' isn't in $updatedSnippetData
        // The action converts $updatedSnippetData['tags'] (null) to an empty array.
        // Your existing assertion for $responseDataForClient['tags'] = [] should still work.
        // To be explicit and match the action's output if $updatedSnippetData['tags'] was null:
        $responseDataForClient['tags'] = []; 

        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($responseDataForClient));
        
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

    public function testSuccessfulUpdateOfDescriptionOnly()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];
        
        $dbModificationCode = 'descModCode1'; 
        $newDescription = "This is an updated description with <html>tags</html> to be stripped.";
        $sanitizedNewDescription = "This is an updated description with tags to be stripped."; // strip_tags result
        
        $originalTitle = "Original Title";
        // Other original fields
        $originalLanguage = "php";
        $originalCode = "echo 'original code';";
        $originalUsername = "testuser";
        $originalCreatedAt = gmdate('Y-m-d H:i:s', time() - 3600);


        $requestBody = [
            'description' => $newDescription, // Only description is being updated
            'modification_code' => $dbModificationCode 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger: No "DIRECT TEST: Title value" debug logs as title is not in requestBody
        // No "Validating title" debug log from main validation loop
        // Expect only logger info for successful update.

        // --- Mocking Database Operations ---
        $stmtVerifyModCode = $this->createMock(PDOStatement::class);
        $stmtUpdate = $this->createMock(PDOStatement::class);
        $stmtFetchFinal = $this->createMock(PDOStatement::class);

        $prepareCallMatcher = new class($this) extends \PHPUnit\Framework\Constraint\Constraint {
            private $testCase;
            private $callCount = 0;
            public function __construct($testCase) { $this->testCase = $testCase; }
            protected function matches($other): bool {
                switch ($this->callCount) {
                    case 0: $this->testCase->assertStringContainsString("SELECT modification_code FROM code_snippets WHERE id = :id", $other); break;
                    case 1: $this->testCase->assertStringContainsString("UPDATE code_snippets SET description = :description, updated_at = :updated_at WHERE id = :id", $other); break; // Query for description update
                    case 2: $this->testCase->assertStringContainsString("SELECT id, title, description, username, language, tags, code, created_at, updated_at FROM code_snippets WHERE id = :id", $other); break;
                    default: $this->testCase->fail('pdoMock->prepare called too many times in custom matcher.');
                }
                $this->callCount++;
                return true;
            }
            public function toString(): string { return 'matches expected SQL for prepare call ' . $this->callCount; }
        };

        $this->pdoMock->expects($this->exactly(3)) 
            ->method('prepare')
            ->with($prepareCallMatcher) 
            ->willReturnOnConsecutiveCalls( 
                $stmtVerifyModCode,
                $stmtUpdate,
                $stmtFetchFinal
            );

        // 1. Modification Code Verification
        $stmtVerifyModCode->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('execute')->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['modification_code' => $dbModificationCode]);

        // 2. Database Update (on $stmtUpdate)
        $stmtUpdate->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) use ($existingSnippetId, $sanitizedNewDescription) {
                $this->assertIsArray($params);
                $this->assertCount(3, $params, "Expected 3 parameters for execute: :id, :description, :updated_at");
                
                $this->assertArrayHasKey(':id', $params);
                $this->assertSame($existingSnippetId, $params[':id']);
                
                $this->assertArrayHasKey(':description', $params);
                $this->assertSame($sanitizedNewDescription, $params[':description']);
                
                $this->assertArrayHasKey(':updated_at', $params);
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $params[':updated_at']);
                
                return true;
            }))
            ->willReturn(true);

        // 3. Fetch of Updated Snippet (on $stmtFetchFinal)
        $stmtFetchFinal->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtFetchFinal->expects($this->once())->method('execute')->willReturn(true);
        
        $expectedUpdatedAt = gmdate('Y-m-d H:i:s'); 
        $updatedSnippetData = [
            'id' => $existingSnippetId,
            'title' => $originalTitle, // Title remains original
            'description' => $sanitizedNewDescription, // Description is updated
            'username' => $originalUsername,
            'language' => $originalLanguage,
            'tags' => null, 
            'code' => $originalCode,
            'created_at' => $originalCreatedAt,
            'updated_at' => $expectedUpdatedAt 
        ];
        $stmtFetchFinal->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($updatedSnippetData);

        // Logger expectation for successful update
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                "Snippet `{$existingSnippetId}` was updated.",
                ['id' => $existingSnippetId, 'updated_fields' => ['description']] // Only description updated
            );
        // No debug logs expected for title validation in this test case
        $this->loggerMock->expects($this->never())->method('debug');


        // Expected 200 response
        $responseDataForClient = $updatedSnippetData;
        $responseDataForClient['tags'] = []; 

        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($responseDataForClient));
        
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

    public function testSuccessfulUpdateOfDescriptionToEmptyString()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];
        
        $dbModificationCode = 'descEmptyMod'; 
        $newDescription = ""; // Empty string
        
        $originalTitle = "Original Title for Empty Desc";
        // Other original fields
        $originalLanguage = "javascript";
        $originalCode = "console.log('original');";
        $originalUsername = "testuser2";
        $originalCreatedAt = gmdate('Y-m-d H:i:s', time() - 7200);


        $requestBody = [
            'description' => $newDescription, 
            'modification_code' => $dbModificationCode 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // --- Mocking Database Operations ---
        $stmtVerifyModCode = $this->createMock(PDOStatement::class);
        $stmtUpdate = $this->createMock(PDOStatement::class);
        $stmtFetchFinal = $this->createMock(PDOStatement::class);

        $prepareCallMatcher = new class($this) extends \PHPUnit\Framework\Constraint\Constraint {
            private $testCase;
            private $callCount = 0;
            public function __construct($testCase) { $this->testCase = $testCase; }
            protected function matches($other): bool {
                switch ($this->callCount) {
                    case 0: $this->testCase->assertStringContainsString("SELECT modification_code FROM code_snippets WHERE id = :id", $other); break;
                    case 1: $this->testCase->assertStringContainsString("UPDATE code_snippets SET description = :description, updated_at = :updated_at WHERE id = :id", $other); break;
                    case 2: $this->testCase->assertStringContainsString("SELECT id, title, description, username, language, tags, code, created_at, updated_at FROM code_snippets WHERE id = :id", $other); break;
                    default: $this->testCase->fail('pdoMock->prepare called too many times in custom matcher.');
                }
                $this->callCount++;
                return true;
            }
            public function toString(): string { return 'matches expected SQL for prepare call ' . $this->callCount; }
        };

        $this->pdoMock->expects($this->exactly(3)) 
            ->method('prepare')
            ->with($prepareCallMatcher) 
            ->willReturnOnConsecutiveCalls( 
                $stmtVerifyModCode,
                $stmtUpdate,
                $stmtFetchFinal
            );

        // 1. Modification Code Verification
        $stmtVerifyModCode->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('execute')->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['modification_code' => $dbModificationCode]);

        // 2. Database Update (on $stmtUpdate)
        $stmtUpdate->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) use ($existingSnippetId, $newDescription) { // $newDescription is already ""
                $this->assertIsArray($params);
                $this->assertCount(3, $params);
                $this->assertSame($existingSnippetId, $params[':id']);
                $this->assertSame($newDescription, $params[':description']); // Expecting ""
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $params[':updated_at']);
                return true;
            }))
            ->willReturn(true);

        // 3. Fetch of Updated Snippet (on $stmtFetchFinal)
        $stmtFetchFinal->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtFetchFinal->expects($this->once())->method('execute')->willReturn(true);
        
        $expectedUpdatedAt = gmdate('Y-m-d H:i:s'); 
        $updatedSnippetData = [
            'id' => $existingSnippetId,
            'title' => $originalTitle,
            'description' => $newDescription, // Should be ""
            'username' => $originalUsername,
            'language' => $originalLanguage,
            'tags' => null, 
            'code' => $originalCode,
            'created_at' => $originalCreatedAt,
            'updated_at' => $expectedUpdatedAt 
        ];
        $stmtFetchFinal->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($updatedSnippetData);

        // Logger expectation
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                "Snippet `{$existingSnippetId}` was updated.",
                ['id' => $existingSnippetId, 'updated_fields' => ['description']]
            );
        $this->loggerMock->expects($this->never())->method('debug');


        // Expected 200 response
        $responseDataForClient = $updatedSnippetData;
        $responseDataForClient['tags'] = []; 

        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($responseDataForClient));
        
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

    public function testSuccessfulUpdateOfDescriptionToNull()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];
        
        $dbModificationCode = 'descNullMod1'; 
        $newDescription = null; // Explicitly null
        
        $originalTitle = "Original Title for Null Desc";
        // Other original fields
        $originalLanguage = "python";
        $originalCode = "print('original for null desc')";
        $originalUsername = "testuser3";
        $originalCreatedAt = gmdate('Y-m-d H:i:s', time() - 10800); // 3 hours ago


        $requestBody = [
            'description' => $newDescription, // Sending null
            'modification_code' => $dbModificationCode 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // --- Mocking Database Operations ---
        $stmtVerifyModCode = $this->createMock(PDOStatement::class);
        $stmtUpdate = $this->createMock(PDOStatement::class);
        $stmtFetchFinal = $this->createMock(PDOStatement::class);

        $prepareCallMatcher = new class($this) extends \PHPUnit\Framework\Constraint\Constraint {
            private $testCase;
            private $callCount = 0;
            public function __construct($testCase) { $this->testCase = $testCase; }
            protected function matches($other): bool {
                switch ($this->callCount) {
                    case 0: $this->testCase->assertStringContainsString("SELECT modification_code FROM code_snippets WHERE id = :id", $other); break;
                    case 1: $this->testCase->assertStringContainsString("UPDATE code_snippets SET description = :description, updated_at = :updated_at WHERE id = :id", $other); break;
                    case 2: $this->testCase->assertStringContainsString("SELECT id, title, description, username, language, tags, code, created_at, updated_at FROM code_snippets WHERE id = :id", $other); break;
                    default: $this->testCase->fail('pdoMock->prepare called too many times in custom matcher.');
                }
                $this->callCount++;
                return true;
            }
            public function toString(): string { return 'matches expected SQL for prepare call ' . $this->callCount; }
        };

        $this->pdoMock->expects($this->exactly(3)) 
            ->method('prepare')
            ->with($prepareCallMatcher) 
            ->willReturnOnConsecutiveCalls( 
                $stmtVerifyModCode,
                $stmtUpdate,
                $stmtFetchFinal
            );

        // 1. Modification Code Verification
        $stmtVerifyModCode->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('execute')->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['modification_code' => $dbModificationCode]);

        // 2. Database Update (on $stmtUpdate)
        $stmtUpdate->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) use ($existingSnippetId, $newDescription) { // $newDescription is null
                $this->assertIsArray($params);
                $this->assertCount(3, $params);
                $this->assertSame($existingSnippetId, $params[':id']);
                $this->assertNull($params[':description']); // Expecting null
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $params[':updated_at']);
                return true;
            }))
            ->willReturn(true);

        // 3. Fetch of Updated Snippet (on $stmtFetchFinal)
        $stmtFetchFinal->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtFetchFinal->expects($this->once())->method('execute')->willReturn(true);
        
        $expectedUpdatedAt = gmdate('Y-m-d H:i:s'); 
        $updatedSnippetData = [
            'id' => $existingSnippetId,
            'title' => $originalTitle,
            'description' => $newDescription, // Should be null
            'username' => $originalUsername,
            'language' => $originalLanguage,
            'tags' => null, 
            'code' => $originalCode,
            'created_at' => $originalCreatedAt,
            'updated_at' => $expectedUpdatedAt 
        ];
        $stmtFetchFinal->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($updatedSnippetData);

        // Logger expectation
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                "Snippet `{$existingSnippetId}` was updated.",
                ['id' => $existingSnippetId, 'updated_fields' => ['description']]
            );
        $this->loggerMock->expects($this->never())->method('debug');


        // Expected 200 response
        $responseDataForClient = $updatedSnippetData;
        $responseDataForClient['tags'] = []; 

        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($responseDataForClient));
        
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

    public function testSuccessfulUpdateOfCodeOnly()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];
        
        $dbModificationCode = 'codeUpdateM1'; 
        $newCodeWithHtml = "<script>alert('xss');</script><?php echo 'Hello World'; ?>";
        // HTMLPurifier will remove <script> and might escape <?php depending on config.
        // Let's assume for now it removes script and keeps php tags as text or escapes them.
        // We'll mock purify to return a specific sanitized string.
        $expectedSanitizedCode = "&lt;?php echo 'Hello World'; ?&gt;"; // Example if purifier escapes PHP tags

        $originalTitle = "Original Title for Code Update";
        $originalDescription = "Original description for code update.";
        // Other original fields
        $originalLanguage = "php";
        $originalUsername = "testuser4";
        $originalCreatedAt = gmdate('Y-m-d H:i:s', time() - 14400); // 4 hours ago

        $requestBody = [
            'code' => $newCodeWithHtml, 
            'modification_code' => $dbModificationCode 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Mock HTMLPurifier
        $this->htmlPurifierMock->expects($this->once())
            ->method('purify')
            ->with($newCodeWithHtml)
            ->willReturn($expectedSanitizedCode);

        // --- Mocking Database Operations ---
        $stmtVerifyModCode = $this->createMock(PDOStatement::class);
        $stmtUpdate = $this->createMock(PDOStatement::class);
        $stmtFetchFinal = $this->createMock(PDOStatement::class);

        $prepareCallMatcher = new class($this) extends \PHPUnit\Framework\Constraint\Constraint {
            private $testCase;
            private $callCount = 0;
            public function __construct($testCase) { $this->testCase = $testCase; }
            protected function matches($other): bool {
                switch ($this->callCount) {
                    case 0: $this->testCase->assertStringContainsString("SELECT modification_code FROM code_snippets WHERE id = :id", $other); break;
                    case 1: $this->testCase->assertStringContainsString("UPDATE code_snippets SET code = :code, updated_at = :updated_at WHERE id = :id", $other); break; // Query for code update
                    case 2: $this->testCase->assertStringContainsString("SELECT id, title, description, username, language, tags, code, created_at, updated_at FROM code_snippets WHERE id = :id", $other); break;
                    default: $this->testCase->fail('pdoMock->prepare called too many times in custom matcher.');
                }
                $this->callCount++;
                return true;
            }
            public function toString(): string { return 'matches expected SQL for prepare call ' . $this->callCount; }
        };

        $this->pdoMock->expects($this->exactly(3)) 
            ->method('prepare')
            ->with($prepareCallMatcher) 
            ->willReturnOnConsecutiveCalls( 
                $stmtVerifyModCode,
                $stmtUpdate,
                $stmtFetchFinal
            );

        // 1. Modification Code Verification
        $stmtVerifyModCode->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('execute')->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['modification_code' => $dbModificationCode]);

        // 2. Database Update (on $stmtUpdate)
        $stmtUpdate->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) use ($existingSnippetId, $expectedSanitizedCode) {
                $this->assertIsArray($params);
                $this->assertCount(3, $params);
                $this->assertSame($existingSnippetId, $params[':id']);
                $this->assertSame($expectedSanitizedCode, $params[':code']); // Expecting sanitized code
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $params[':updated_at']);
                return true;
            }))
            ->willReturn(true);

        // 3. Fetch of Updated Snippet (on $stmtFetchFinal)
        $stmtFetchFinal->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtFetchFinal->expects($this->once())->method('execute')->willReturn(true);
        
        $expectedUpdatedAt = gmdate('Y-m-d H:i:s'); 
        $updatedSnippetData = [
            'id' => $existingSnippetId,
            'title' => $originalTitle,
            'description' => $originalDescription,
            'username' => $originalUsername,
            'language' => $originalLanguage,
            'tags' => null, 
            'code' => $expectedSanitizedCode, // Code is updated with sanitized version
            'created_at' => $originalCreatedAt,
            'updated_at' => $expectedUpdatedAt 
        ];
        $stmtFetchFinal->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($updatedSnippetData);

        // Logger expectation
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                "Snippet `{$existingSnippetId}` was updated.",
                ['id' => $existingSnippetId, 'updated_fields' => ['code']]
            );
        $this->loggerMock->expects($this->never())->method('debug');


        // Expected 200 response
        $responseDataForClient = $updatedSnippetData;
        $responseDataForClient['tags'] = []; 

        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($responseDataForClient));
        
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

    public function testSuccessfulUpdateOfLanguageOnly()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];
        
        $dbModificationCode = 'langUpdateM1'; 
        $newLanguageWithSpacesAndTags = "  <p>javascript</p>  ";
        $expectedSanitizedLanguage = "javascript";

        $originalTitle = "Original Title for Lang Update";
        $originalDescription = "Original description for lang update.";
        $originalCode = "console.log('hello');";
        // Other original fields
        $originalUsername = "testuser5";
        $originalCreatedAt = gmdate('Y-m-d H:i:s', time() - 18000); // 5 hours ago

        $requestBody = [
            'language' => $newLanguageWithSpacesAndTags, 
            'modification_code' => $dbModificationCode 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // No HTMLPurifier mock needed for language, it uses strip_tags/trim

        // --- Mocking Database Operations ---
        $stmtVerifyModCode = $this->createMock(PDOStatement::class);
        $stmtUpdate = $this->createMock(PDOStatement::class);
        $stmtFetchFinal = $this->createMock(PDOStatement::class);

        $prepareCallMatcher = new class($this) extends \PHPUnit\Framework\Constraint\Constraint {
            private $testCase;
            private $callCount = 0;
            public function __construct($testCase) { $this->testCase = $testCase; }
            protected function matches($other): bool {
                switch ($this->callCount) {
                    case 0: $this->testCase->assertStringContainsString("SELECT modification_code FROM code_snippets WHERE id = :id", $other); break;
                    case 1: $this->testCase->assertStringContainsString("UPDATE code_snippets SET language = :language, updated_at = :updated_at WHERE id = :id", $other); break; // Query for language update
                    case 2: $this->testCase->assertStringContainsString("SELECT id, title, description, username, language, tags, code, created_at, updated_at FROM code_snippets WHERE id = :id", $other); break;
                    default: $this->testCase->fail('pdoMock->prepare called too many times in custom matcher.');
                }
                $this->callCount++;
                return true;
            }
            public function toString(): string { return 'matches expected SQL for prepare call ' . $this->callCount; }
        };

        $this->pdoMock->expects($this->exactly(3)) 
            ->method('prepare')
            ->with($prepareCallMatcher) 
            ->willReturnOnConsecutiveCalls( 
                $stmtVerifyModCode,
                $stmtUpdate,
                $stmtFetchFinal
            );

        // 1. Modification Code Verification
        $stmtVerifyModCode->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('execute')->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['modification_code' => $dbModificationCode]);

        // 2. Database Update (on $stmtUpdate)
        $stmtUpdate->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) use ($existingSnippetId, $expectedSanitizedLanguage) {
                $this->assertIsArray($params);
                $this->assertCount(3, $params); // id, language, updated_at
                $this->assertSame($existingSnippetId, $params[':id']);
                $this->assertSame($expectedSanitizedLanguage, $params[':language']); // Expecting sanitized language
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $params[':updated_at']);
                return true;
            }))
            ->willReturn(true);

        // 3. Fetch of Updated Snippet (on $stmtFetchFinal)
        $stmtFetchFinal->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtFetchFinal->expects($this->once())->method('execute')->willReturn(true);
        
        $expectedUpdatedAt = gmdate('Y-m-d H:i:s'); 
        $updatedSnippetData = [
            'id' => $existingSnippetId,
            'title' => $originalTitle,
            'description' => $originalDescription,
            'username' => $originalUsername,
            'language' => $expectedSanitizedLanguage, // Language is updated
            'tags' => null, 
            'code' => $originalCode,
            'created_at' => $originalCreatedAt,
            'updated_at' => $expectedUpdatedAt 
        ];
        $stmtFetchFinal->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($updatedSnippetData);

        // Logger expectation
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                "Snippet `{$existingSnippetId}` was updated.",
                ['id' => $existingSnippetId, 'updated_fields' => ['language']]
            );
        $this->loggerMock->expects($this->never())->method('debug');


        // Expected 200 response
        $responseDataForClient = $updatedSnippetData;
        $responseDataForClient['tags'] = []; 

        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($responseDataForClient));
        
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

    public function testSuccessfulUpdateOfTagsOnly()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];
        
        $dbModificationCode = 'tagsUpdateM1'; 
        $newTagsArray = ['php', 'api', 'slim framework']; // Input tags
        // Sanitization in action: strip_tags, trim, remove empty. For these clean tags, sanitized is same.
        $expectedSanitizedTagsArray = ['php', 'api', 'slim framework']; 
        // Expected PostgreSQL array literal string for DB storage
        $expectedDbTagsString = '{"php","api","slim framework"}'; 

        $originalTitle = "Original Title for Tags Update";
        $originalDescription = "Original description for tags update.";
        $originalCode = "echo 'tags update test';";
        $originalLanguage = "php";
        $originalUsername = "testuser6";
        $originalCreatedAt = gmdate('Y-m-d H:i:s', time() - 21600); // 6 hours ago

        $requestBody = [
            'tags' => $newTagsArray, 
            'modification_code' => $dbModificationCode 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // --- Mocking Database Operations ---
        $stmtVerifyModCode = $this->createMock(PDOStatement::class);
        $stmtUpdate = $this->createMock(PDOStatement::class);
        $stmtFetchFinal = $this->createMock(PDOStatement::class);

        $prepareCallMatcher = new class($this) extends \PHPUnit\Framework\Constraint\Constraint {
            private $testCase;
            private $callCount = 0;
            public function __construct($testCase) { $this->testCase = $testCase; }
            protected function matches($other): bool {
                switch ($this->callCount) {
                    case 0: $this->testCase->assertStringContainsString("SELECT modification_code FROM code_snippets WHERE id = :id", $other); break;
                    case 1: $this->testCase->assertStringContainsString("UPDATE code_snippets SET tags = :tags, updated_at = :updated_at WHERE id = :id", $other); break; // Query for tags update
                    case 2: $this->testCase->assertStringContainsString("SELECT id, title, description, username, language, tags, code, created_at, updated_at FROM code_snippets WHERE id = :id", $other); break;
                    default: $this->testCase->fail('pdoMock->prepare called too many times in custom matcher.');
                }
                $this->callCount++;
                return true;
            }
            public function toString(): string { return 'matches expected SQL for prepare call ' . $this->callCount; }
        };

        $this->pdoMock->expects($this->exactly(3)) 
            ->method('prepare')
            ->with($prepareCallMatcher) 
            ->willReturnOnConsecutiveCalls( 
                $stmtVerifyModCode,
                $stmtUpdate,
                $stmtFetchFinal
            );

        // 1. Modification Code Verification
        $stmtVerifyModCode->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('execute')->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['modification_code' => $dbModificationCode]);

        // 2. Database Update (on $stmtUpdate)
        $stmtUpdate->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) use ($existingSnippetId, $expectedDbTagsString) {
                $this->assertIsArray($params);
                $this->assertCount(3, $params); // id, tags, updated_at
                $this->assertSame($existingSnippetId, $params[':id']);
                $this->assertSame($expectedDbTagsString, $params[':tags']); // Expecting PG array string
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $params[':updated_at']);
                return true;
            }))
            ->willReturn(true);

        // 3. Fetch of Updated Snippet (on $stmtFetchFinal)
        $stmtFetchFinal->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtFetchFinal->expects($this->once())->method('execute')->willReturn(true);
        
        $expectedUpdatedAt = gmdate('Y-m-d H:i:s'); 
        $updatedSnippetDataFromDb = [ // This is what the DB fetch would return
            'id' => $existingSnippetId,
            'title' => $originalTitle,
            'description' => $originalDescription,
            'username' => $originalUsername,
            'language' => $originalLanguage,
            'tags' => $expectedDbTagsString, // DB returns the string
            'code' => $originalCode,
            'created_at' => $originalCreatedAt,
            'updated_at' => $expectedUpdatedAt 
        ];
        $stmtFetchFinal->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($updatedSnippetDataFromDb);

        // Logger expectation
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                "Snippet `{$existingSnippetId}` was updated.",
                ['id' => $existingSnippetId, 'updated_fields' => ['tags']]
            );
        $this->loggerMock->expects($this->never())->method('debug');

        // Expected 200 response (action converts DB tags string back to PHP array)
        $responseDataForClient = $updatedSnippetDataFromDb;
        $responseDataForClient['tags'] = $expectedSanitizedTagsArray; // Action converts string to array

        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($responseDataForClient));
        
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

    public function testSuccessfulUpdateOfTagsToEmptyArray()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];
        
        $dbModificationCode = 'tagsEmptyM12'; 
        $newTagsArray = []; // Update to empty array
        $expectedSanitizedTagsArray = []; 
        $expectedDbTagsString = '{}'; // PostgreSQL representation of an empty array

        $originalTitle = "Original Title for Empty Tags Update";
        $originalDescription = "Original description for empty tags update.";
        $originalCode = "echo 'empty tags test';";
        $originalLanguage = "php";
        $originalUsername = "testuser7";
        $originalCreatedAt = gmdate('Y-m-d H:i:s', time() - 25200); // 7 hours ago

        $requestBody = [
            'tags' => $newTagsArray, 
            'modification_code' => $dbModificationCode 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // --- Mocking Database Operations ---
        $stmtVerifyModCode = $this->createMock(PDOStatement::class);
        $stmtUpdate = $this->createMock(PDOStatement::class);
        $stmtFetchFinal = $this->createMock(PDOStatement::class);

        $prepareCallMatcher = new class($this) extends \PHPUnit\Framework\Constraint\Constraint {
            private $testCase;
            private $callCount = 0;
            public function __construct($testCase) { $this->testCase = $testCase; }
            protected function matches($other): bool {
                switch ($this->callCount) {
                    case 0: $this->testCase->assertStringContainsString("SELECT modification_code FROM code_snippets WHERE id = :id", $other); break;
                    case 1: $this->testCase->assertStringContainsString("UPDATE code_snippets SET tags = :tags, updated_at = :updated_at WHERE id = :id", $other); break;
                    case 2: $this->testCase->assertStringContainsString("SELECT id, title, description, username, language, tags, code, created_at, updated_at FROM code_snippets WHERE id = :id", $other); break;
                    default: $this->testCase->fail('pdoMock->prepare called too many times in custom matcher.');
                }
                $this->callCount++;
                return true;
            }
            public function toString(): string { return 'matches expected SQL for prepare call ' . $this->callCount; }
        };

        $this->pdoMock->expects($this->exactly(3)) 
            ->method('prepare')
            ->with($prepareCallMatcher) 
            ->willReturnOnConsecutiveCalls( 
                $stmtVerifyModCode,
                $stmtUpdate,
                $stmtFetchFinal
            );

        // 1. Modification Code Verification
        $stmtVerifyModCode->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('execute')->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['modification_code' => $dbModificationCode]);

        // 2. Database Update (on $stmtUpdate)
        $stmtUpdate->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) use ($existingSnippetId, $expectedDbTagsString) {
                $this->assertIsArray($params);
                $this->assertCount(3, $params); // id, tags, updated_at
                $this->assertSame($existingSnippetId, $params[':id']);
                $this->assertSame($expectedDbTagsString, $params[':tags']); // Expecting '{}'
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $params[':updated_at']);
                return true;
            }))
            ->willReturn(true);

        // 3. Fetch of Updated Snippet (on $stmtFetchFinal)
        $stmtFetchFinal->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtFetchFinal->expects($this->once())->method('execute')->willReturn(true);
        
        $expectedUpdatedAt = gmdate('Y-m-d H:i:s'); 
        $updatedSnippetDataFromDb = [
            'id' => $existingSnippetId,
            'title' => $originalTitle,
            'description' => $originalDescription,
            'username' => $originalUsername,
            'language' => $originalLanguage,
            'tags' => $expectedDbTagsString, // DB returns '{}'
            'code' => $originalCode,
            'created_at' => $originalCreatedAt,
            'updated_at' => $expectedUpdatedAt 
        ];
        $stmtFetchFinal->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($updatedSnippetDataFromDb);

        // Logger expectation
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                "Snippet `{$existingSnippetId}` was updated.",
                ['id' => $existingSnippetId, 'updated_fields' => ['tags']]
            );
        $this->loggerMock->expects($this->never())->method('debug');

        // Expected 200 response
        $responseDataForClient = $updatedSnippetDataFromDb;
        $responseDataForClient['tags'] = $expectedSanitizedTagsArray; // Action converts '{}' to []

        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($responseDataForClient));
        
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

    public function testSuccessfulUpdateOfMultipleFields()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];
        
        $dbModificationCode = 'multiFldUpd1'; // 12 chars

        $newTitle = "Updated Title for Multi-Field Test";
        $newCodeWithHtml = "<?php // multi-field update \n echo '<p>Test</p>'; ?>";
        $expectedSanitizedCode = "&lt;?php // multi-field update \n echo '&lt;p&gt;Test&lt;/p&gt;'; ?&gt;"; // Example if purifier escapes PHP and HTML

        $originalDescription = "Original description for multi-field update.";
        $originalLanguage = "php";
        $originalUsername = "testuser8";
        $originalCreatedAt = gmdate('Y-m-d H:i:s', time() - 28800); // 8 hours ago

        $requestBody = [
            'title' => $newTitle,
            'code' => $newCodeWithHtml,
            'modification_code' => $dbModificationCode 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Mock HTMLPurifier for the code field
        $this->htmlPurifierMock->expects($this->once())
            ->method('purify')
            ->with($newCodeWithHtml)
            ->willReturn($expectedSanitizedCode);
        
        // Logger expectations for title validation
        $debugCallCount = 0;
        // Expect 3 debug calls when title is present and of normal length (1-500)
        $this->loggerMock->expects($this->exactly(3)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $newTitle) {
                    if ($debugCallCount === 0) { 
                        $this->assertSame("DIRECT TEST: Title value", $message);
                        $this->assertSame(['title' => $newTitle, 'length_php' => strlen($newTitle)], $context);
                    } elseif ($debugCallCount === 1) { 
                        // This is the message that was previously missed by the mock
                        $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message);
                        $this->assertEmpty($context); // This log call has an empty context array
                    } elseif ($debugCallCount === 2) { 
                        $this->assertSame("Validating title", $message); 
                        $this->assertSame(['value' => $newTitle, 'length' => strlen($newTitle)], $context);
                    }
                    $debugCallCount++;
                    return null; 
                }
            );


        // --- Mocking Database Operations ---
        $stmtVerifyModCode = $this->createMock(PDOStatement::class);
        $stmtUpdate = $this->createMock(PDOStatement::class);
        $stmtFetchFinal = $this->createMock(PDOStatement::class);

        $prepareCallMatcher = new class($this) extends \PHPUnit\Framework\Constraint\Constraint {
            private $testCase;
            private $callCount = 0;
            public function __construct($testCase) { $this->testCase = $testCase; }
            protected function matches($other): bool {
                switch ($this->callCount) {
                    case 0: $this->testCase->assertStringContainsString("SELECT modification_code FROM code_snippets WHERE id = :id", $other); break;
                    case 1: 
                        $this->testCase->assertStringContainsString("UPDATE code_snippets SET", $other);
                        $this->testCase->assertStringContainsString("title = :title", $other);
                        $this->testCase->assertStringContainsString("code = :code", $other);
                        $this->testCase->assertStringContainsString("updated_at = :updated_at WHERE id = :id", $other);
                        break;
                    case 2: $this->testCase->assertStringContainsString("SELECT id, title, description, username, language, tags, code, created_at, updated_at FROM code_snippets WHERE id = :id", $other); break;
                    default: $this->testCase->fail('pdoMock->prepare called too many times in custom matcher.');
                }
                $this->callCount++;
                return true;
            }
            public function toString(): string { return 'matches expected SQL for prepare call ' . $this->callCount; }
        };

        $this->pdoMock->expects($this->exactly(3)) 
            ->method('prepare')
            ->with($prepareCallMatcher) 
            ->willReturnOnConsecutiveCalls( 
                $stmtVerifyModCode,
                $stmtUpdate,
                $stmtFetchFinal
            );

        // 1. Modification Code Verification
        $stmtVerifyModCode->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('execute')->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['modification_code' => $dbModificationCode]);

        // 2. Database Update (on $stmtUpdate)
        $stmtUpdate->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) use ($existingSnippetId, $newTitle, $expectedSanitizedCode) {
                $this->assertIsArray($params);
                // Expect :id, :title, :code, :updated_at
                $this->assertCount(4, $params); 
                
                $this->assertSame($existingSnippetId, $params[':id']);
                $this->assertSame(strip_tags(trim($newTitle)), $params[':title']);
                $this->assertSame($expectedSanitizedCode, $params[':code']);
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $params[':updated_at']);
                return true;
            }))
            ->willReturn(true);

        // 3. Fetch of Updated Snippet (on $stmtFetchFinal)
        $stmtFetchFinal->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtFetchFinal->expects($this->once())->method('execute')->willReturn(true);
        
        $expectedUpdatedAt = gmdate('Y-m-d H:i:s'); 
        $updatedSnippetData = [
            'id' => $existingSnippetId,
            'title' => strip_tags(trim($newTitle)), // Updated
            'description' => $originalDescription, // Original
            'username' => $originalUsername,
            'language' => $originalLanguage, // Original
            'tags' => null, 
            'code' => $expectedSanitizedCode, // Updated
            'created_at' => $originalCreatedAt,
            'updated_at' => $expectedUpdatedAt 
        ];
        $stmtFetchFinal->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($updatedSnippetData);

        // Logger expectation for successful update
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                "Snippet `{$existingSnippetId}` was updated.",
                // The order in updated_fields might vary depending on how array_key_exists iterates
                // So we check for presence and count.
                $this->callback(function ($context) use ($existingSnippetId) {
                    $this->assertSame($existingSnippetId, $context['id']);
                    $this->assertIsArray($context['updated_fields']);
                    $this->assertCount(2, $context['updated_fields']);
                    $this->assertContains('title', $context['updated_fields']);
                    $this->assertContains('code', $context['updated_fields']);
                    return true;
                })
            );

        // Expected 200 response
        $responseDataForClient = $updatedSnippetData;
        $responseDataForClient['tags'] = []; 

        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($responseDataForClient));
        
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

    public function testUpdateReturns400IfNoUpdatableFieldsProvided()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];
        
        $dbModificationCode = 'noFieldsUpd1'; // 12 chars, valid

        $requestBody = [
            // No updatable fields like title, description, code, language, tags
            'modification_code' => $dbModificationCode 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // No HTMLPurifier mock needed as no relevant fields are provided.
        // No debug logs for title validation expected.
        $this->loggerMock->expects($this->never())->method('debug');

        // --- Mocking Database Operations ---
        // Only modification code verification should happen.
        $stmtVerifyModCode = $this->createMock(PDOStatement::class);

        // Expect only one call to prepare (for modification code verification)
        $this->pdoMock->expects($this->once()) 
            ->method('prepare')
            ->with($this->stringContains("SELECT modification_code FROM code_snippets WHERE id = :id"))
            ->willReturn($stmtVerifyModCode);

        // 1. Modification Code Verification
        $stmtVerifyModCode->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('execute')->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['modification_code' => $dbModificationCode]);

        // Logger expectation for "No updatable fields"
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'No updatable fields provided for snippet.',
                ['id' => $existingSnippetId, 'payload' => $requestBody]
            );
        
        // No warning log for general validation failure, as mod code is valid.
        $this->loggerMock->expects($this->never())->method('warning');


        // Expected 400 response
        $expectedErrorResponse = ['error' => 'No updatable fields provided or fields became empty after sanitization.'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorResponse));
        
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($this->responseMock);

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testUpdateReturns400IfTitleBecomesEmptyAfterSanitization()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];
        
        $dbModificationCode = 'titleEmptyS1'; // 12 chars, should be valid by itself
        $titleThatBecomesEmpty = "   "; // This input causes initial validation to fail

        $requestBody = [
            'title' => $titleThatBecomesEmpty,
            'modification_code' => $dbModificationCode 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger expectations for title validation (debug calls - 4 expected due to initial validation failure)
        $debugCallCount = 0;
        $this->loggerMock->expects($this->exactly(4)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $titleThatBecomesEmpty) {
                    if ($debugCallCount === 0) { 
                        $this->assertSame("DIRECT TEST: Title value", $message);
                        $this->assertSame(['title' => $titleThatBecomesEmpty, 'length_php' => strlen($titleThatBecomesEmpty)], $context);
                    } elseif ($debugCallCount === 1) { 
                        $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message);
                        $this->assertEmpty($context);
                    } elseif ($debugCallCount === 2) { 
                        $this->assertSame("Validating title", $message);
                        $this->assertSame(['value' => $titleThatBecomesEmpty, 'length' => strlen($titleThatBecomesEmpty)], $context);
                    } elseif ($debugCallCount === 3) { 
                        $this->assertSame("Validation FAILED for title", $message);
                        $this->assertArrayHasKey('messages', $context); 
                    }
                    $debugCallCount++;
                    return null; 
                }
            );
        
        // --- Mocking Database Operations ---
        // Since initial validation for title fails, db->prepare for mod code verification is NOT called.
        $this->pdoMock->expects($this->never()) // <<< CHANGE HERE
            ->method('prepare');
        // Consequently, no expectations for $stmtVerifyModCode->bindParam, execute, fetch are needed.

        // Logger expectation for "validation failed"
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Snippet update validation failed.', 
                $this->callback(function ($context) use ($existingSnippetId, $requestBody) {
                    $this->assertSame($existingSnippetId, $context['id']);
                    $this->assertArrayHasKey('title', $context['errors']);
                    $this->assertSame(['Title' => 'Title must not be empty'], $context['errors']['title']); 
                    $this->assertSame($requestBody, $context['payload']);
                    return true;
                })
            );
        
        // Expected 400 response
        $expectedErrorResponse = ['errors' => ['title' => ['Title' => 'Title must not be empty']]]; 
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorResponse));
        
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);
        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($this->responseMock);

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }
    
    public function testUpdateReturns400IfCodeBecomesEmptyAfterSanitization()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];
        
        $dbModificationCode = 'codeEmptyS12'; // 12 chars, valid
        $codeThatBecomesEmpty = "<script>alert('only script');</script>"; // Non-empty, but purifier will empty it

        $requestBody = [
            'code' => $codeThatBecomesEmpty,
            'modification_code' => $dbModificationCode 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Mock HTMLPurifier to return an empty string for this specific input
        $this->htmlPurifierMock->expects($this->once())
            ->method('purify')
            ->with($codeThatBecomesEmpty)
            ->willReturn(""); // Simulates purifier removing all content

        // No debug logs for title validation expected
        $this->loggerMock->expects($this->never())->method('debug');
        
        // --- Mocking Database Operations ---
        // Modification code verification should happen before the sanitization error for code.
        $stmtVerifyModCode = $this->createMock(PDOStatement::class);

        $this->pdoMock->expects($this->once()) 
            ->method('prepare')
            ->with($this->stringContains("SELECT modification_code FROM code_snippets WHERE id = :id"))
            ->willReturn($stmtVerifyModCode);

        $stmtVerifyModCode->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('execute')->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['modification_code' => $dbModificationCode]);

        // Logger expectation for "validation failed after sanitization"
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                // This message comes from the block after sanitization checks
                'Snippet update validation failed after sanitization.', 
                $this->callback(function ($context) use ($existingSnippetId, $requestBody) {
                    $this->assertSame($existingSnippetId, $context['id']);
                    $this->assertArrayHasKey('code', $context['errors']);
                    $this->assertSame(['Code cannot be empty.'], $context['errors']['code']);
                    $this->assertSame($requestBody, $context['payload']);
                    return true;
                })
            );
        
        // Expected 400 response
        $expectedErrorResponse = ['errors' => ['code' => ['Code cannot be empty.']]];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorResponse));
        
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($this->responseMock);

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

    public function testUpdateReturns400IfLanguageBecomesEmptyAfterSanitization()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];
        
        $dbModificationCode = 'langEmptyS12'; // 12 chars, valid
        $languageThatBecomesEmpty = "   <p></p>   "; // Passes initial validation, but trim/strip_tags makes it ""

        $requestBody = [
            'language' => $languageThatBecomesEmpty,
            'modification_code' => $dbModificationCode 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // No HTMLPurifier mock needed for language.
        // No debug logs for title validation expected.
        $this->loggerMock->expects($this->never())->method('debug');
        
        // --- Mocking Database Operations ---
        // Modification code verification should happen before the sanitization error for language.
        $stmtVerifyModCode = $this->createMock(PDOStatement::class);

        $this->pdoMock->expects($this->once()) 
            ->method('prepare')
            ->with($this->stringContains("SELECT modification_code FROM code_snippets WHERE id = :id"))
            ->willReturn($stmtVerifyModCode);

        $stmtVerifyModCode->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('execute')->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['modification_code' => $dbModificationCode]);

        // Logger expectation for "validation failed after sanitization"
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Snippet update validation failed after sanitization.', 
                $this->callback(function ($context) use ($existingSnippetId, $requestBody) {
                    $this->assertSame($existingSnippetId, $context['id']);
                    $this->assertArrayHasKey('language', $context['errors']);
                    $this->assertSame(['Language cannot be empty.'], $context['errors']['language']);
                    $this->assertSame($requestBody, $context['payload']);
                    return true;
                })
            );
        
        // Expected 400 response
        $expectedErrorResponse = ['errors' => ['language' => ['Language cannot be empty.']]];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorResponse));
        
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($this->responseMock);

        $returnedResponse = $action($this->requestMock, $this->responseMock, $args);
        $this->assertSame($this->responseMock, $returnedResponse);
    }

public function testUpdateFailsAndReturns500IfDatabaseUpdateExecuteFails()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];
        
        $dbModificationCode = 'dbExecFail12'; // 12 chars
        $newTitle = "Title for DB Execute Fail Test";

        $requestBody = [
            'title' => $newTitle,
            'modification_code' => $dbModificationCode 
        ]; 

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger expectations for title validation (will pass)
        $debugCallCount = 0;
        $this->loggerMock->expects($this->exactly(3)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $newTitle) {
                    if ($debugCallCount === 0) { $this->assertSame("DIRECT TEST: Title value", $message); }
                    elseif ($debugCallCount === 1) { $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message); }
                    elseif ($debugCallCount === 2) { $this->assertSame("Validating title", $message); }
                    $debugCallCount++;
                    return null; 
                }
            );
        
        // --- Mocking Database Operations ---
        $stmtVerifyModCode = $this->createMock(PDOStatement::class);
        $stmtUpdate = $this->createMock(PDOStatement::class); 

        $this->pdoMock->expects($this->exactly(2)) 
            ->method('prepare')
            ->willReturnCallback(function ($sql) use ($stmtVerifyModCode, $stmtUpdate, $newTitle) {
                // Match the first prepare call (modification code verification)
                // Be a bit flexible with strpos, though it should be exact.
                if (strpos($sql, "SELECT modification_code FROM code_snippets WHERE id = :id") !== false && strpos($sql, "UPDATE") === false) {
                    return $stmtVerifyModCode;
                }
                // Match the second prepare call (the actual update)
                // For this test, only 'title' is being updated.
                elseif (strpos($sql, "UPDATE code_snippets SET") !== false &&
                        strpos($sql, "title = :title") !== false &&
                        strpos($sql, "updated_at = :updated_at") !== false && // Ensure updated_at is also there
                        strpos($sql, "WHERE id = :id") !== false) {
                    return $stmtUpdate;
                }
                // If an unexpected SQL is passed, this will help debug.
                // The test will still fail due to null return, but this gives info.
                fwrite(STDERR, "PHPUnit Debug: Unexpected SQL for pdoMock->prepare: " . $sql . "\n");
                return null; 
            });

        // 1. Modification Code Verification (success)
        $stmtVerifyModCode->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('execute')->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['modification_code' => $dbModificationCode]);

        // 2. Database Update (on $stmtUpdate) - Simulate execute failure
        $stmtUpdate->expects($this->once())
            ->method('execute')
            // We can be less strict about params if execute itself fails
            ->willReturn(false); // Simulate PDOStatement::execute() returning false

        // Logger expectation for critical error
        $this->loggerMock->expects($this->once())
            ->method('critical')
            ->with(
                'Failed to execute snippet update.',
                $this->callback(function ($context) use ($existingSnippetId) {
                    $this->assertSame($existingSnippetId, $context['id']);
                    $this->assertArrayHasKey('query_params', $context); 
                    $this->assertArrayHasKey('error', $context); 
                    return true;
                })
            );
        
        $this->loggerMock->expects($this->never())->method('info');

        $expectedErrorResponse = ['error' => 'Internal Server Error: Could not update snippet.'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorResponse));
        
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

    public function testUpdateReturns500IfModCodeVerificationPrepareThrowsPDOException()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);

        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];

        $requestBody = [
            'title' => 'Valid Title for PDOException Test',
            'modification_code' => 'modCodeRight' // 12 chars
        ];

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger expectations for title validation (will pass before the PDOException)
        $debugCallCount = 0;
        $this->loggerMock->expects($this->exactly(3)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $requestBody) {
                    if ($debugCallCount === 0) { $this->assertSame("DIRECT TEST: Title value", $message); }
                    elseif ($debugCallCount === 1) { $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message); }
                    elseif ($debugCallCount === 2) { $this->assertSame("Validating title", $message); }
                    $debugCallCount++;
                    return null; 
                }
            );

        // --- Mocking Database Operations ---
        // The first call to db->prepare (for mod code verification) will throw a PDOException.
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("SELECT modification_code FROM code_snippets WHERE id = :id"))
            ->willThrowException(new \PDOException("Test DB prepare failure for mod code verification"));

        // Logger expectation for the error caught by the main try-catch block
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains("Database error updating snippet `{$existingSnippetId}`: Test DB prepare failure for mod code verification"),
                $this->callback(function ($context) use ($existingSnippetId) {
                    $this->assertSame($existingSnippetId, $context['id']);
                    $this->assertInstanceOf(\PDOException::class, $context['exception']);
                    return true;
                })
            );
        
        // Ensure other specific logs are not called
        $this->loggerMock->expects($this->never())->method('warning');
        $this->loggerMock->expects($this->never())->method('info');
        $this->loggerMock->expects($this->never())->method('critical');


        // Expected 500 response
        $expectedErrorResponse = ['error' => 'Failed to update snippet due to a database issue.'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorResponse));
        
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

    public function testUpdateReturns500IfUpdatePrepareThrowsPDOException()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);

        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];

        $dbModificationCode = 'updatePrepEx'; // 12 chars
        $newTitle = "Title for Update Prepare Exception";

        $requestBody = [
            'title' => $newTitle,
            'modification_code' => $dbModificationCode 
        ];

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger expectations for title validation (will pass)
        $debugCallCount = 0;
        $this->loggerMock->expects($this->exactly(3)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $newTitle) {
                    if ($debugCallCount === 0) { $this->assertSame("DIRECT TEST: Title value", $message); }
                    elseif ($debugCallCount === 1) { $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message); }
                    elseif ($debugCallCount === 2) { $this->assertSame("Validating title", $message); }
                    $debugCallCount++;
                    return null; 
                }
            );

        // --- Mocking Database Operations ---
        $stmtVerifyModCode = $this->createMock(PDOStatement::class);
        // No $stmtUpdate needed as prepare for it will throw

        $prepareCallCount = 0;
        $this->pdoMock->expects($this->exactly(2)) // Mod code verify prepare, then update prepare (which throws)
            ->method('prepare')
            ->willReturnCallback(
                function ($sql) use (&$prepareCallCount, $stmtVerifyModCode, $existingSnippetId) {
                    $prepareCallCount++;
                    if ($prepareCallCount === 1) { // First call: Modification code verification
                        $this->assertStringContainsString("SELECT modification_code FROM code_snippets WHERE id = :id", $sql);
                        return $stmtVerifyModCode;
                    } elseif ($prepareCallCount === 2) { // Second call: The UPDATE statement prepare
                        $this->assertStringContainsString("UPDATE code_snippets SET", $sql);
                        throw new \PDOException("Test DB prepare failure for UPDATE statement");
                    }
                    // Should not be reached if exactly(2) is correct
                    $this->fail("pdoMock->prepare called unexpectedly for SQL: " . $sql); 
                    return null; // Should not happen
                }
            );

        // 1. Modification Code Verification (success)
        $stmtVerifyModCode->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('execute')->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['modification_code' => $dbModificationCode]);
        
        // Logger expectation for the error caught by the main try-catch block
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains("Database error updating snippet `{$existingSnippetId}`: Test DB prepare failure for UPDATE statement"),
                $this->callback(function ($context) use ($existingSnippetId) {
                    $this->assertSame($existingSnippetId, $context['id']);
                    $this->assertInstanceOf(\PDOException::class, $context['exception']);
                    return true;
                })
            );
        
        $this->loggerMock->expects($this->never())->method('warning');
        $this->loggerMock->expects($this->never())->method('info');
        $this->loggerMock->expects($this->never())->method('critical');


        $expectedErrorResponse = ['error' => 'Failed to update snippet due to a database issue.'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorResponse));
        
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

    public function testUpdateSucceedsButFetchFailsToRetrieveUpdatedSnippetReturns500()
    {
        $action = new UpdateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);

        $existingSnippetId = Uuid::uuid4()->toString();
        $args = ['id' => $existingSnippetId];

        $dbModificationCode = 'fetchFail123'; // 12 chars
        $newTitle = "Title for Fetch Fail Test";

        $requestBody = [
            'title' => $newTitle,
            'modification_code' => $dbModificationCode 
        ];

        $this->requestMock->expects($this->once())
            ->method('getParsedBody')
            ->willReturn($requestBody);

        // Logger expectations for title validation (will pass)
        $debugCallCount = 0;
        $this->loggerMock->expects($this->exactly(3)) 
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context = []) use (&$debugCallCount, $newTitle) {
                    if ($debugCallCount === 0) { $this->assertSame("DIRECT TEST: Title value", $message); }
                    elseif ($debugCallCount === 1) { $this->assertSame("DIRECT TEST: Title length validation PASSED (unexpected for long title)", $message); }
                    elseif ($debugCallCount === 2) { $this->assertSame("Validating title", $message); }
                    $debugCallCount++;
                    return null; 
                }
            );

        // --- Mocking Database Operations ---
        $stmtVerifyModCode = $this->createMock(PDOStatement::class);
        $stmtUpdate = $this->createMock(PDOStatement::class);
        $stmtFetch = $this->createMock(PDOStatement::class); // For the final fetch

        $prepareCallCount = 0;
        $this->pdoMock->expects($this->exactly(3)) // Mod code verify, UPDATE, final SELECT
            ->method('prepare')
            ->willReturnCallback(
                function ($sql) use (&$prepareCallCount, $stmtVerifyModCode, $stmtUpdate, $stmtFetch) {
                    $prepareCallCount++;
                    if ($prepareCallCount === 1) { // Mod code verification
                        $this->assertStringContainsString("SELECT modification_code FROM code_snippets WHERE id = :id", $sql);
                        return $stmtVerifyModCode;
                    } elseif ($prepareCallCount === 2) { // UPDATE statement
                        $this->assertStringContainsString("UPDATE code_snippets SET", $sql);
                        return $stmtUpdate;
                    } elseif ($prepareCallCount === 3) { // Final SELECT statement
                        $this->assertStringContainsString("SELECT id, title, description, username, language, tags, code, created_at, updated_at FROM code_snippets WHERE id = :id", $sql);
                        return $stmtFetch;
                    }
                    $this->fail("pdoMock->prepare called unexpectedly for SQL: " . $sql);
                    return null;
                }
            );

        // 1. Modification Code Verification (success)
        $stmtVerifyModCode->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('execute')->willReturn(true);
        $stmtVerifyModCode->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['modification_code' => $dbModificationCode]);

        // 2. Database Update (success)
        $stmtUpdate->expects($this->once())->method('execute')->willReturn(true); // Update is successful

        // 3. Final Fetch (prepare and execute succeed, but fetch returns false)
        $stmtFetch->expects($this->once())->method('bindParam')->with(':id', $existingSnippetId)->willReturn(true);
        $stmtFetch->expects($this->once())->method('execute')->willReturn(true);
        $stmtFetch->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(false); // Simulate snippet not found on fetch

        // Logger expectation for the specific error
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to retrieve updated snippet after update.',
                ['id' => $existingSnippetId]
            );
        
        // Ensure other specific logs are not called
        $this->loggerMock->expects($this->never())->method('warning');
        $this->loggerMock->expects($this->never())->method('info'); // No successful update info log
        $this->loggerMock->expects($this->never())->method('critical');


        $expectedErrorResponse = ['error' => 'Failed to retrieve updated snippet after update'];
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with(json_encode($expectedErrorResponse));
        
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
<?php // THIS MUST BE THE VERY FIRST LINE

namespace App\Tests\Actions\Snippets;

use PHPUnit\Framework\TestCase;
use App\Actions\Snippets\CreateSnippetAction;
use PDO;
use PDOStatement;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use HTMLPurifier;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\NativeType; // <--- Add this line

class CreateSnippetActionTest extends TestCase
{
    protected $pdoMock;
    protected $stmtMock;
    protected $htmlPurifierMock;
    protected $loggerMock;
    protected $requestMock;
    protected $responseMock;
    protected $streamMock;

    private $responseStatusCode;
    private $pdoPrepareCallCount = 0;

    protected function setUp(): void
    {
        // ---- PHP.INI DEBUG ----
        // $loaded_ini = php_ini_loaded_file();
        // $log_message = "DEBUG php.ini from CreateSnippetActionTest::setUp(): " . ($loaded_ini ?: 'none') . " | Timestamp: " . time();
        // error_log($log_message);
        // ---- END PHP.INI DEBUG ----

        $this->pdoMock = $this->createMock(PDO::class);
        $this->stmtMock = $this->createMock(PDOStatement::class);
        $this->htmlPurifierMock = $this->createMock(HTMLPurifier::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->requestMock = $this->createMock(ServerRequestInterface::class);
        $this->responseMock = $this->createMock(ResponseInterface::class);
        $this->streamMock = $this->createMock(StreamInterface::class);

        $this->responseStatusCode = 200; 
        $this->pdoPrepareCallCount = 0;

        $this->responseMock->method('getBody')->willReturn($this->streamMock);
        $this->responseMock->method('withHeader')->willReturn($this->responseMock); 
        $this->responseMock->method('withStatus')
            ->willReturnCallback(function (int $code) {
                $this->responseStatusCode = $code;
                return $this->responseMock;
            });
        $this->responseMock->method('getStatusCode')
            ->willReturnCallback(function () {
                return $this->responseStatusCode;
            });
    }

    // ADD THIS HELPER METHOD:
    protected function mockSnippetCountQuery(int $currentCount): void
    {
        $countStmtMock = $this->createMock(PDOStatement::class);
        $countStmtMock->expects($this->once()) // Expect fetchColumn to be called once
            ->method('fetchColumn')
            ->willReturn($currentCount); // Return the desired count

        // Important: This expectation should be specific enough if pdoMock->query()
        // might be called for other reasons in more complex scenarios.
        // For now, assuming it's only for the count.
        $this->pdoMock->expects($this->once()) // Expect query to be called once for the count
            ->method('query')
            ->with("SELECT COUNT(*) FROM code_snippets") // With this specific SQL
            ->willReturn($countStmtMock); // Return the mock statement for the count query
    }

    public function testSuccessfulSnippetCreation()
    {
        $action = new CreateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        $this->mockSnippetCountQuery(0); // Now this call will be valid

        $requestBody = [
            'title' => 'Test Title',
            'code' => 'echo "test";',
            'language' => 'php',
            'username' => 'TestUser',
            'description' => 'Test Description',
            'tags' => ['tag1', 'tag "with" quotes']
        ];
        $this->requestMock->method('getParsedBody')->willReturn($requestBody);

        $mockSnippetId = 'test-uuid-12345'; // You might need to control this if your action generates it
                                        // Or ensure your mocked return data uses the generated one.
                                        // For simplicity, let's assume the action generates it and we match broadly.

        // Mocking prepare to be called twice (for INSERT and SELECT)
        $this->pdoMock->expects($this->exactly(2))
            ->method('prepare')
            ->willReturn($this->stmtMock);

        // Mocking execute to be called twice and succeed
        $this->stmtMock->expects($this->exactly(2))
            ->method('execute')
            ->willReturn(true);

        // Mocking fetch for the SELECT statement to return a snippet
        $expectedCreatedSnippetDataFromDb = [
            'id' => $mockSnippetId, 
            'title' => $requestBody['title'],
            'description' => $requestBody['description'],
            'username' => $requestBody['username'],
            'language' => $requestBody['language'],
            'code' => $requestBody['code'], 
            'tags' => '{test-uuid-12345,"tag1","tag \\"with\\" quotes"}', 
            'modification_code' => new IsType(NativeType::String), // <--- Corrected usage
            'created_at' => new IsType(NativeType::String),        // <--- Corrected usage
            'updated_at' => new IsType(NativeType::String),        // <--- Corrected usage
        ];
        // This mock is crucial for $createdSnippet to be truthy
        $this->stmtMock->expects($this->once()) // Fetch is called once for the SELECT
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedCreatedSnippetDataFromDb);

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with($this->stringContains("Snippet with ID")); // Check if this message is accurate

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(201)
            ->willReturn($this->responseMock);
    
        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->streamMock->expects($this->once())
         ->method('write')
         ->with($this->callback(function($jsonString) use ($requestBody) {
             $data = json_decode($jsonString, true);
             return $data !== null && $data['title'] === $requestBody['title'];
         }));

        $action($this->requestMock, $this->responseMock);
    }

    public function testCreationFailsWithMissingTitle()
    {
        $action = new CreateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        $this->mockSnippetCountQuery(0); // <--- ADDED

        $requestBody = [
            // 'title' => '', // Intentionally missing or empty to trigger validation
            'code' => 'echo "test";',
            'language' => 'php',
            'description' => 'Test Description',
            'username' => 'TestUser'
        ];
        $this->requestMock->method('getParsedBody')->willReturn($requestBody);

        // Expect logger->warning to be called due to validation failure
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Snippet creation validation failed.'),
                $this->arrayHasKey('errors')
            );

        // Expect response->getBody()->write() to be called with error details
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($jsonString) {
                $data = json_decode($jsonString, true);
                return isset($data['errors']['title']); // Check that title has a validation error
            }));

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

        $action($this->requestMock, $this->responseMock);
    }

    public function testCreationFailsWithMissingCode()
    {
        $action = new CreateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        $this->mockSnippetCountQuery(0); // <--- ADDED

        $requestBody = [
            'title' => 'Test Title for Missing Code',
            // 'code' => 'echo "test";' // Intentionally missing
            'language' => 'php',
            'description' => 'Test Description',
            'username' => 'TestUser'
        ];
        $this->requestMock->method('getParsedBody')->willReturn($requestBody);

        // Expect logger->warning to be called due to validation failure
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Snippet creation validation failed.'),
                $this->callback(function ($context) { 
                    return isset($context['errors']['code']) && 
                           is_array($context['errors']['code']) &&
                           // Adjust the expected message based on Respect\Validation's output for null
                           in_array('Code must be of type string', $context['errors']['code']); 
                })
            );

        // Expect response->getBody()->write() to be called with error details
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($jsonString) {
                $data = json_decode($jsonString, true);
                return isset($data['errors']['code']) &&
                       is_array($data['errors']['code']) &&
                       // Adjust the expected message
                       in_array('Code must be of type string', $data['errors']['code']);
            }));

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

        $action($this->requestMock, $this->responseMock);
    }

    public function testCreationFailsWithMissingLanguage()
    {
        $action = new CreateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        $this->mockSnippetCountQuery(0); // <--- ADDED

        $requestBody = [
            'title' => 'Test Title for Missing Language',
            'code' => 'echo "Hello World!";',
            // 'language' => 'php', // Intentionally missing
            'description' => 'Test Description',
            'username' => 'TestUser'
        ];
        $this->requestMock->method('getParsedBody')->willReturn($requestBody);

        // Expect logger->warning to be called due to validation failure
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Snippet creation validation failed.'),
                $this->callback(function ($context) {
                    return isset($context['errors']['language']) &&
                           is_array($context['errors']['language']) &&
                           in_array('Language must have a length between 1 and 50', $context['errors']['language']); // <<< CORRECTED
                })
            );

        // Expect response->getBody()->write() to be called with error details
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($jsonString) {
                $data = json_decode($jsonString, true);
                return isset($data['errors']['language']) &&
                       is_array($data['errors']['language']) &&
                       in_array('Language must have a length between 1 and 50', $data['errors']['language']); // <<< CORRECTED
            }));

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

        $action($this->requestMock, $this->responseMock);
    }

    public function testCreationFailsWithTitleTooLong()
    {
        $action = new CreateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        $this->mockSnippetCountQuery(0); // <--- ADDED

        $tooLongTitle = str_repeat('a', 256); // Title is 256 characters, max is 255

        $requestBody = [
            'title' => $tooLongTitle,
            'code' => 'echo "Valid code";',
            'language' => 'php',
            'description' => 'Test Description',
            'username' => 'TestUser'
        ];
        $this->requestMock->method('getParsedBody')->willReturn($requestBody);

        // Expect logger->warning to be called due to validation failure
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Snippet creation validation failed.'),
                $this->callback(function ($context) {
                    return isset($context['errors']['title']) &&
                           is_array($context['errors']['title']) &&
                           in_array('Title must have a length between 1 and 255', $context['errors']['title']);
                })
            );

        // Expect response->getBody()->write() to be called with error details
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($jsonString) {
                $data = json_decode($jsonString, true);
                return isset($data['errors']['title']) &&
                       is_array($data['errors']['title']) &&
                       in_array('Title must have a length between 1 and 255', $data['errors']['title']);
            }));

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

        $action($this->requestMock, $this->responseMock);
    }

    public function testCreationFailsWithLanguageTooLong()
    {
        $action = new CreateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        $this->mockSnippetCountQuery(0); // <--- ADDED

        $tooLongLanguage = str_repeat('l', 51); // Language is 51 characters, max is 50

        $requestBody = [
            'title' => 'Valid Title',
            'code' => 'echo "Valid code";',
            'language' => $tooLongLanguage,
            'description' => 'Test Description',
            'username' => 'TestUser'
        ];
        $this->requestMock->method('getParsedBody')->willReturn($requestBody);

        // Expect logger->warning to be called due to validation failure
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Snippet creation validation failed.'),
                $this->callback(function ($context) {
                    return isset($context['errors']['language']) &&
                           is_array($context['errors']['language']) &&
                           in_array('Language must have a length between 1 and 50', $context['errors']['language']);
                })
            );

        // Expect response->getBody()->write() to be called with error details
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($jsonString) {
                $data = json_decode($jsonString, true);
                return isset($data['errors']['language']) &&
                       is_array($data['errors']['language']) &&
                       in_array('Language must have a length between 1 and 50', $data['errors']['language']);
            }));

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

        $action($this->requestMock, $this->responseMock);
    }

    public function testCreationFailsWithDescriptionTooLong()
    {
        $action = new CreateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        $this->mockSnippetCountQuery(0); // <--- ADDED

        $tooLongDescription = str_repeat('d', 1001); // Description is 1001 characters, max is 1000

        $requestBody = [
            'title' => 'Valid Title',
            'code' => 'echo "Valid code";',
            'language' => 'php',
            'description' => $tooLongDescription, // Too long description
            'username' => 'TestUser'
        ];
        $this->requestMock->method('getParsedBody')->willReturn($requestBody);

        // Expect logger->warning to be called due to validation failure
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Snippet creation validation failed.'),
                $this->callback(function ($context) {
                    return isset($context['errors']['description']) &&
                           is_array($context['errors']['description']) &&
                           in_array('Description must have a length lower than or equal to 1000', $context['errors']['description']); // <<< CORRECTED
                })
            );

        // Expect response->getBody()->write() to be called with error details
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($jsonString) {
                $data = json_decode($jsonString, true);
                return isset($data['errors']['description']) &&
                       is_array($data['errors']['description']) &&
                       in_array('Description must have a length lower than or equal to 1000', $data['errors']['description']); // <<< CORRECTED
            }));

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

        $action($this->requestMock, $this->responseMock);
    }

    public function testCreationFailsWithTagTooLong()
    {
        $action = new CreateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        $this->mockSnippetCountQuery(0); // <--- ADDED

        $tooLongTag = str_repeat('t', 51); // Tag is 51 characters, max is 50
        $expectedErrorMessage = sprintf('These rules must pass for "%s"', $tooLongTag);


        $requestBody = [
            'title' => 'Valid Title',
            'code' => 'echo "Valid code";',
            'language' => 'php',
            'tags' => ['validTag', $tooLongTag], // One tag is too long
            'username' => 'TestUser'
        ];
        $this->requestMock->method('getParsedBody')->willReturn($requestBody);

        // Expect logger->warning to be called due to validation failure
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Snippet creation validation failed.'),
                $this->callback(function ($context) use ($expectedErrorMessage) {
                    return isset($context['errors']['tags']) &&
                           is_array($context['errors']['tags']) &&
                           in_array($expectedErrorMessage, $context['errors']['tags']); // <<< CORRECTED
                })
            );

        // Expect response->getBody()->write() to be called with error details
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($jsonString) use ($expectedErrorMessage) {
                $data = json_decode($jsonString, true);
                return isset($data['errors']['tags']) &&
                       is_array($data['errors']['tags']) &&
                       in_array($expectedErrorMessage, $data['errors']['tags']); // <<< CORRECTED
            }));

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

        $action($this->requestMock, $this->responseMock);
    }

    public function testCreationFailsWithTagsNotAnArray()
    {
        $action = new CreateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        $this->mockSnippetCountQuery(0); // <--- ADDED

        $requestBody = [
            'title' => 'Valid Title',
            'code' => 'echo "Valid code";',
            'language' => 'php',
            'tags' => 'this-is-not-an-array', // Tags is a string, not an array
            'username' => 'TestUser'
        ];
        $this->requestMock->method('getParsedBody')->willReturn($requestBody);

        // The actual error message from Respect\Validation is just 'false' for the field name
        // when arrayType() fails in this context.

        // Expect logger->warning to be called due to validation failure
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Snippet creation validation failed.'),
                $this->callback(function ($context) {
                    return isset($context['errors']['tags']) &&
                           is_array($context['errors']['tags']) &&
                           // Expecting the 'Tags' key to have a value of false
                           array_key_exists('Tags', $context['errors']['tags']) &&
                           $context['errors']['tags']['Tags'] === false;
                })
            );

        // Expect response->getBody()->write() to be called with error details
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($jsonString) {
                $data = json_decode($jsonString, true);
                return isset($data['errors']['tags']) &&
                       is_array($data['errors']['tags']) &&
                       // Expecting the 'Tags' key to have a value of false
                       array_key_exists('Tags', $data['errors']['tags']) &&
                       $data['errors']['tags']['Tags'] === false;
            }));

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

        $action($this->requestMock, $this->responseMock);
    }

    public function testCreationFailsWithTagNotAString()
    {
        $action = new CreateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        $this->mockSnippetCountQuery(0); // <--- ADDED

        $nonStringTag = 12345; // A non-string value for a tag
        // Corrected: Respect\Validation does not put quotes around the value in this message format
        $expectedErrorMessage = sprintf('These rules must pass for %s', (string)$nonStringTag);

        $requestBody = [
            'title' => 'Valid Title',
            'code' => 'echo "Valid code";',
            'language' => 'php',
            'tags' => ['validTag', $nonStringTag], // One tag is an integer
            'username' => 'TestUser'
        ];
        $this->requestMock->method('getParsedBody')->willReturn($requestBody);

        // Expect logger->warning to be called due to validation failure
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Snippet creation validation failed.'),
                $this->callback(function ($context) use ($expectedErrorMessage) {
                    return isset($context['errors']['tags']) &&
                           is_array($context['errors']['tags']) &&
                           in_array($expectedErrorMessage, $context['errors']['tags']);
                })
            );

        // Expect response->getBody()->write() to be called with error details
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($jsonString) use ($expectedErrorMessage) {
                $data = json_decode($jsonString, true);
                return isset($data['errors']['tags']) &&
                       is_array($data['errors']['tags']) &&
                       in_array($expectedErrorMessage, $data['errors']['tags']);
            }));

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

        $action($this->requestMock, $this->responseMock);
    }

    public function testCreationFailsWithUsernameTooLong()
    {
        $action = new CreateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);
        
        // Call mockSnippetCountQuery only ONCE
        $this->mockSnippetCountQuery(0); 

        $tooLongUsername = str_repeat('u', 51); // Username is 51 characters, max is 50

        $requestBody = [
            'title' => 'Valid Title',
            'code' => 'echo "Valid code";',
            'language' => 'php',
            'username' => $tooLongUsername, // Too long username
            'tags' => ['validTag']
        ];
        $this->requestMock->method('getParsedBody')->willReturn($requestBody);

        $expectedErrorMessage = 'Username must have a length between 1 and 50';

        // Expect logger->warning to be called due to validation failure
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Snippet creation validation failed.'),
                $this->callback(function ($context) use ($expectedErrorMessage) {
                    return isset($context['errors']['username']) &&
                           is_array($context['errors']['username']) &&
                           in_array($expectedErrorMessage, $context['errors']['username']);
                })
            );

        // Expect response->getBody()->write() to be called with error details
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($jsonString) use ($expectedErrorMessage) {
                $data = json_decode($jsonString, true);
                return isset($data['errors']['username']) &&
                       is_array($data['errors']['username']) &&
                       in_array($expectedErrorMessage, $data['errors']['username']);
            }));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($this->responseMock);
        
        // PDO should not be touched for snippet insertion if validation fails early
        // The query for COUNT(*) will have been mocked by mockSnippetCountQuery.
        // So, we only expect 'prepare' to never be called for the INSERT/SELECT of the snippet itself.
        $this->pdoMock->expects($this->never())->method('prepare');

        $action($this->requestMock, $this->responseMock);
    }

    public function testCreationFailsWhenSnippetLimitIsReached()
    {
        $action = new CreateSnippetAction($this->pdoMock, $this->htmlPurifierMock, $this->loggerMock);

        // Define the limit as it is in CreateSnippetAction.php
        $maxSnippetsLimit = 500; 

        // Mock the snippet count query to return the max limit
        $this->mockSnippetCountQuery($maxSnippetsLimit);

        // Request body is needed for getParsedBody, but its content doesn't matter much
        // as the action should exit before processing it deeply.
        $requestBody = [
            'title' => 'Attempt to Create When Full',
            'code' => 'echo "this should not be saved";',
            'language' => 'php',
            'username' => 'TestUserLimit',
            'description' => 'Testing snippet limit',
            'tags' => ['limit-test']
        ];
        $this->requestMock->method('getParsedBody')->willReturn($requestBody);

        // Expect logger->warning to be called due to the limit being reached
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains("Snippet creation denied: Maximum snippet limit reached."),
                $this->callback(function ($context) use ($maxSnippetsLimit) {
                    return isset($context['current_count']) && $context['current_count'] === $maxSnippetsLimit &&
                           isset($context['limit']) && $context['limit'] === $maxSnippetsLimit;
                })
            );

        // Expect response->getBody()->write() to be called with the specific error message
        $expectedErrorMessage = 'The maximum number of snippets (' . $maxSnippetsLimit . ') has been reached. Please try again later after some snippets have been removed.';
        $this->streamMock->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($jsonString) use ($expectedErrorMessage) {
                $data = json_decode($jsonString, true);
                return isset($data['error']) && $data['error'] === 'Service Unavailable' &&
                       isset($data['message']) && $data['message'] === $expectedErrorMessage;
            }));

        $this->responseMock->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($this->responseMock);

        $this->responseMock->expects($this->once())
            ->method('withStatus')
            ->with(503) // Service Unavailable
            ->willReturn($this->responseMock);
        
        // PDO prepare for snippet insertion should NOT be called if the limit is reached
        $this->pdoMock->expects($this->never())->method('prepare');

        $action($this->requestMock, $this->responseMock);
    }
}
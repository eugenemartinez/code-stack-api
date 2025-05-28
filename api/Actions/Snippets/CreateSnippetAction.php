<?php

declare(strict_types=1);

namespace App\Actions\Snippets; // Using a namespace for organization

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PDO;
use Ramsey\Uuid\Uuid;
use Respect\Validation\Validator as v; // For input validation
use HTMLPurifier;                     // For HTML sanitization
use Psr\Log\LoggerInterface;          // For logging

class CreateSnippetAction
{
    private PDO $db;
    private HTMLPurifier $htmlPurifier;
    private LoggerInterface $logger;
    private const MAX_SNIPPETS_LIMIT = 5; // Define the limit

    public function __construct(PDO $db, HTMLPurifier $htmlPurifier, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->htmlPurifier = $htmlPurifier;
        $this->logger = $logger;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // ---- PHP.INI DEBUG inside Action ----
        // $actionIniFile = php_ini_loaded_file();
        // $actionErrorLogDirective = ini_get('error_log');
        // $actionLogErrorsDirective = ini_get('log_errors');
        // $debugMessage = "DEBUG from Action __invoke: INI: $actionIniFile | error_log directive: $actionErrorLogDirective | log_errors directive: $actionLogErrorsDirective | Timestamp: " . time();
        // error_log($debugMessage); 
        // ---- END PHP.INI DEBUG ----

        // error_log("CreateSnippetAction invoked from action file. Timestamp: " . time()); 
        
        // --- 0. Check Snippet Count Limit ---
        try {
            $countStmt = $this->db->query("SELECT COUNT(*) FROM code_snippets");
            $currentSnippetCount = (int)$countStmt->fetchColumn();

            if ($currentSnippetCount >= self::MAX_SNIPPETS_LIMIT) {
                $this->logger->warning("Snippet creation denied: Maximum snippet limit reached.", [
                    'current_count' => $currentSnippetCount,
                    'limit' => self::MAX_SNIPPETS_LIMIT
                ]);
                $errorPayload = [
                    'error' => 'Service Unavailable',
                    'message' => 'The maximum number of snippets (' . self::MAX_SNIPPETS_LIMIT . ') has been reached. Please try again later after some snippets have been removed.'
                ];
                $response->getBody()->write(json_encode($errorPayload));
                // 503 Service Unavailable is appropriate here, or 403 Forbidden
                return $response->withHeader('Content-Type', 'application/json')->withStatus(503);
            }
        } catch (\PDOException $e) {
            $this->logger->error("Database error checking snippet count: " . $e->getMessage(), ['exception' => $e]);
            $response->getBody()->write(json_encode(['error' => 'Failed to check snippet capacity due to a database issue.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
        
        $parsedBody = $request->getParsedBody();

        // --- 1. Define Validation Rules ---
        $validationRules = [
            'title' => v::notEmpty()->length(1, 255)->setName('Title'),
            'description' => v::optional(v::stringType()->length(null, 1000))->setName('Description'),
            'code' => v::notEmpty()->stringType()->setName('Code'),
            'language' => v::notEmpty()->stringType()->length(1, 50)->setName('Language'),
            'tags' => v::optional(v::arrayType()->each(v::stringType()->length(1, 50)))->setName('Tags'),
            'username' => v::optional(v::stringType()->length(1, 50))->setName('Username'), // Changed 100 to 50
        ];

        // --- 2. Validate Data ---
        $validationErrors = [];
        foreach ($validationRules as $field => $rule) {
            try {
                $valueToValidate = $parsedBody[$field] ?? null;
                // Let Respect\Validation handle optional logic internally based on how rule was defined
                $rule->assert($valueToValidate); 
            } catch (\Respect\Validation\Exceptions\NestedValidationException $exception) {
                $validationErrors[$field] = $exception->getMessages();
            }
        }

        if (!empty($validationErrors)) {
            $this->logger->warning('Snippet creation validation failed.', ['errors' => $validationErrors, 'payload' => $parsedBody]);
            $response->getBody()->write(json_encode(['errors' => $validationErrors]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // --- 3. Sanitize Data ---
        $sanitizedData = [];
        $sanitizedData['title'] = isset($parsedBody['title']) ? strip_tags(trim($parsedBody['title'])) : '';
        $sanitizedData['description'] = isset($parsedBody['description']) ? strip_tags(trim($parsedBody['description'])) : null;
        $sanitizedData['code'] = isset($parsedBody['code']) ? $this->htmlPurifier->purify($parsedBody['code']) : '';
        $sanitizedData['language'] = isset($parsedBody['language']) ? strip_tags(trim($parsedBody['language'])) : '';
        
        $sanitizedData['tags'] = [];
        if (isset($parsedBody['tags']) && is_array($parsedBody['tags'])) {
            foreach ($parsedBody['tags'] as $tag) {
                if (is_string($tag)) {
                    $cleanTag = strip_tags(trim($tag));
                    if (!empty($cleanTag)) { 
                        $sanitizedData['tags'][] = $cleanTag;
                    }
                }
            }
        }
        $sanitizedData['username'] = isset($parsedBody['username']) ? strip_tags(trim($parsedBody['username'])) : null;

        // --- 4. Proceed with Snippet Creation Logic ---
        $snippetId = Uuid::uuid4()->toString();
        $modificationCode = substr(bin2hex(random_bytes(8)), 0, 12);

        if (empty($sanitizedData['username'])) {
            $adjectives = [
                'Agile', 'Async', 'Atomic', 'Binary', 'Boolean', 'BugFree', 'Cached', 'Clean', 'Cloud', 'Code', 
                'Compiled', 'Cyber', 'Debug', 'Dynamic', 'Elegant', 'Fluent', 'Functional', 'Git', 'Global', 
                'Hashed', 'Indexed', 'Inline', 'Json', 'Kernel', 'Lambda', 'Lean', 'Legacy', 'Linked', 'Logic',
                'Macro', 'Micro', 'Modular', 'Nimble', 'Open', 'Pixel', 'Poly', 'Private', 'Public', 'Pure', 
                'Quick', 'Reactive', 'Recursive', 'Restful', 'Robust', 'Root', 'Scalar', 'Secure', 'Serial', 
                'Serverless', 'Smart', 'Solid', 'Static', 'Stream', 'Swift', 'Sync', 'Syntax', 'Terse', 'Typed', 
                'Valid', 'Vector', 'Virtual', 'Visual', 'Wired', 'Zen'
            ];
            $nouns = [
                'Algorithm', 'Api', 'Array', 'Avatar', 'Backend', 'Bit', 'Bot', 'Branch', 'Browser', 'Buffer', 
                'Bug', 'Build', 'Bundle', 'Byte', 'Cache', 'Callback', 'Class', 'Client', 'Cloud', 'Code', 
                'Coder', 'Commit', 'Compiler', 'Component', 'Console', 'Container', 'Core', 'Cpu', 'Crud', 
                'Cursor', 'Daemon', 'Data', 'Database', 'Debugger', 'Deploy', 'Dev', 'Docker', 'Domain', 
                'Endpoint', 'Engine', 'Entity', 'Enum', 'Event', 'Exception', 'Fetch', 'Fiber', 'Field', 'File', 
                'Firewall', 'Firmware', 'Flag', 'Flow', 'Framework', 'Frontend', 'Function', 'Future', 'Gateway', 
                'Git', 'Glitch', 'Handler', 'Hardware', 'Hash', 'Header', 'Heap', 'Hook', 'Host', 'Html', 'Http', 
                'Hub', 'Icon', 'Ide', 'Index', 'Instance', 'Interface', 'Kernel', 'Key', 'Keyword', 'Lambda', 
                'Layer', 'Layout', 'Lib', 'Linker', 'Linux', 'List', 'Load', 'Lock', 'Log', 'Logic', 'Loop', 
                'Machine', 'Macro', 'Main', 'Manifest', 'Map', 'Markup', 'Memory', 'Merge', 'Meta', 'Method', 
                'Micro', 'Middleware', 'Mock', 'Model', 'Module', 'Monitor', 'Mvc', 'Native', 'Network', 'Node', 
                'Object', 'Packet', 'Page', 'Param', 'Parse', 'Patch', 'Path', 'Pipe', 'Pixel', 'Plugin', 'Pointer', 
                'Pool', 'Port', 'Process', 'Program', 'Promise', 'Protocol', 'Proxy', 'Query', 'Queue', 'Ram', 
                'React', 'Repo', 'Request', 'Resource', 'Rest', 'Root', 'Route', 'Runtime', 'Scalar', 'Schema', 
                'Scope', 'Script', 'Sdk', 'Server', 'Service', 'Session', 'Shader', 'Shell', 'Signal', 'Singleton', 
                'Site', 'Snippet', 'Socket', 'Source', 'Stack', 'State', 'Stream', 'Struct', 'Style', 'Svg', 
                'Sync', 'Syntax', 'Table', 'Tag', 'Target', 'Template', 'Terminal', 'Test', 'Text', 'Thread', 
                'Token', 'Tool', 'Tree', 'Type', 'Url', 'User', 'Uuid', 'Value', 'Var', 'Vector', 'Version', 
                'View', 'Virtual', 'Web', 'Widget', 'Worker', 'Xml', 'Yaml'
            ];
            $selectedAdjective = $adjectives[array_rand($adjectives)];
            $selectedNoun = $nouns[array_rand($nouns)];
            $sanitizedData['username'] = $selectedAdjective . $selectedNoun;
        }
        
        $tagsJson = '{}'; 
        if (!empty($sanitizedData['tags'])) {
            $escapedTags = array_map(function($tag) {
                return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $tag) . '"';
            }, $sanitizedData['tags']);
            $tagsJson = '{' . implode(',', $escapedTags) . '}';
        }

        try {
            $sql = "INSERT INTO code_snippets (id, title, description, username, language, code, tags, modification_code, created_at, updated_at) 
                    VALUES (:id, :title, :description, :username, :language, :code, :tags, :modification_code, NOW(), NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':id' => $snippetId,
                ':title' => $sanitizedData['title'],
                ':description' => $sanitizedData['description'],
                ':username' => $sanitizedData['username'],
                ':language' => $sanitizedData['language'],
                ':code' => $sanitizedData['code'],
                ':tags' => $tagsJson,
                ':modification_code' => $modificationCode
            ]);

            $selectStmt = $this->db->prepare("SELECT id, title, description, username, language, code, tags, modification_code, created_at, updated_at FROM code_snippets WHERE id = :id");
            $selectStmt->execute([':id' => $snippetId]);
            $createdSnippet = $selectStmt->fetch(PDO::FETCH_ASSOC);

            if ($createdSnippet) {
                if (!empty($createdSnippet['tags']) && is_string($createdSnippet['tags'])) {
                    $tagsString = trim($createdSnippet['tags'], '{}');
                     if (empty($tagsString)) {
                        $createdSnippet['tags'] = [];
                    } else {
                        preg_match_all('/(?<=^|,)(?:"((?:[^"\\\\]|\\\\.)*)"|([^,"]*))/', $tagsString, $matches, PREG_SET_ORDER);
                        $parsedTags = [];
                        foreach ($matches as $match) {
                            if (isset($match[2]) && $match[2] !== '') { $parsedTags[] = $match[2]; } 
                            elseif (isset($match[1])) { $parsedTags[] = str_replace(['\\\\', '\\"'], ['\\', '"'], $match[1]); }
                        }
                        $createdSnippet['tags'] = $parsedTags;
                    }
                } else {
                    $createdSnippet['tags'] = [];
                }
                
                $this->logger->info("Snippet with ID `{$snippetId}` was created.", ['snippet_id' => $snippetId, 'username' => $createdSnippet['username']]);
                $response->getBody()->write(json_encode($createdSnippet));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
            } else {
                $this->logger->error("Failed to retrieve created snippet after insert.", ['snippet_id' => $snippetId]);
                $response->getBody()->write(json_encode(['error' => 'Failed to retrieve created snippet']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }

        } catch (\PDOException $e) {
            $this->logger->error("Database error creating snippet: " . $e->getMessage(), [
                'exception' => $e, 
                'sql_params' => [ 
                    ':title' => $sanitizedData['title'], 
                ]
            ]);
            $response->getBody()->write(json_encode(['error' => 'Failed to create snippet due to a database issue.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
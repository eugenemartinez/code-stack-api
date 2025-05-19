<?php

// Go up two levels from /tests/bootstrap.php to the project root /codestack/
$projectRoot = dirname(__DIR__);

// Include Composer's autoloader
require_once $projectRoot . '/vendor/autoload.php';

// Load environment variables from .env file
try {
    // Create Dotenv instance for the project root
    $dotenv = Dotenv\Dotenv::createImmutable($projectRoot);
    $dotenv->load(); // Load .env variables into $_ENV and getenv()
} catch (\Dotenv\Exception\InvalidPathException $e) {
    // Handle the case where .env file might be missing, though it shouldn't be for local dev/testing
    error_log("Warning: Could not load .env file in tests/bootstrap.php: " . $e->getMessage());
    // You might decide to throw an exception or exit if .env is critical for tests
}

// You can also set a default TESTING environment variable here if it's not set by phpunit.xml
// For example, though phpunit.xml.dist should already be doing this:
// if (empty($_ENV['TESTING'])) {
//     $_ENV['TESTING'] = 'true';
// }

// Any other global setup for tests can go here
?>
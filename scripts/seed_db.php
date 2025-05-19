<?php

require_once __DIR__ . '/../vendor/autoload.php'; // Autoload Composer dependencies

use Ramsey\Uuid\Uuid; // Import Ramsey Uuid

// Load environment variables from the parent directory's .env file
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
} catch (\Dotenv\Exception\InvalidPathException $e) {
    die("Could not load .env file: " . $e->getMessage() . "\nMake sure it exists in the 'codestack' directory.\n");
}

// --- Database Connection Details (from .env for local setup) ---
$dbTargetDsn = $_ENV['CLOUD_DB_DSN'] ?? null; // Default to local, can be changed for cloud

if (!$dbTargetDsn) {
    die("Target DSN (e.g., CLOUD_DB_DSN) not found in .env file.\n");
}

$dbUrlParts = parse_url($dbTargetDsn);

if ($dbUrlParts === false || !isset($dbUrlParts['host'], $dbUrlParts['path'], $dbUrlParts['user'])) {
    die("Could not parse DSN: " . $dbTargetDsn . "\nEnsure it's a valid PostgreSQL connection string.\n");
}

$dbHost = $dbUrlParts['host'];
$dbPort = $dbUrlParts['port'] ?? 5432;
$dbName = ltrim($dbUrlParts['path'], '/');
$dbUser = $dbUrlParts['user'];
$dbPass = $dbUrlParts['pass'] ?? '';

$pdoDsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// --- Read Seed Data ---
$seedFilePath = __DIR__ . '/seed_db.json';
if (!file_exists($seedFilePath)) {
    die("Seed file not found: {$seedFilePath}\n");
}

$jsonSeedData = file_get_contents($seedFilePath);
if ($jsonSeedData === false) {
    die("Could not read seed file: {$seedFilePath}\n");
}

$snippets = json_decode($jsonSeedData, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error decoding JSON from seed file: " . json_last_error_msg() . "\n");
}

if (empty($snippets)) {
    echo "No data to seed.\n";
    exit(0);
}

// --- Connect to Database and Seed ---
try {
    $pdo = new PDO($pdoDsn, $dbUser, $dbPass, $pdoOptions);
    echo "Successfully connected to the database: {$dbName} on host {$dbHost}\n";

    $sql = "INSERT INTO code_snippets (id, title, description, username, language, tags, code, modification_code, created_at, updated_at)
            VALUES (:id, :title, :description, :username, :language, :tags, :code, :modification_code, :created_at, :updated_at)
            ON CONFLICT (modification_code) DO UPDATE SET
                title = EXCLUDED.title,
                description = EXCLUDED.description,
                username = EXCLUDED.username,
                language = EXCLUDED.language,
                tags = EXCLUDED.tags,
                code = EXCLUDED.code,
                updated_at = EXCLUDED.updated_at,
                -- id = EXCLUDED.id, -- Let's keep the original ID on conflict
                created_at = CASE WHEN code_snippets.created_at IS NULL THEN EXCLUDED.created_at ELSE code_snippets.created_at END"; // Keep original created_at

    $stmt = $pdo->prepare($sql);

    $count = 0;
    foreach ($snippets as $snippet) {
        // Auto-generate ID
        $generatedId = Uuid::uuid4()->toString();

        // Auto-generate modification_code based on content for deterministic updates
        // Using a combination of title, username, and language.
        $title = $snippet['title'] ?? '';
        $username = $snippet['username'] ?? '';
        $language = $snippet['language'] ?? '';
        // Simple hash - for more robustness, consider more fields or a more complex hash
        $generatedModificationCode = substr(hash('sha256', $title . $username . $language), 0, 20);


        $pgTags = null;
        if (!empty($snippet['tags']) && is_array($snippet['tags'])) {
            $escapedTags = array_map(function ($tag) {
                return str_replace(['{', '}', '"', '\\'], '', $tag);
            }, $snippet['tags']);
            $pgTags = '{' . implode(',', $escapedTags) . '}';
        }

        try {
            $stmt->execute([
                ':id' => $generatedId,
                ':title' => $title,
                ':description' => $snippet['description'] ?? null,
                ':username' => $username,
                ':language' => $language,
                ':tags' => $pgTags,
                ':code' => $snippet['code'] ?? null,
                ':modification_code' => $generatedModificationCode,
                ':created_at' => $snippet['created_at'] ?? gmdate("Y-m-d\TH:i:s\Z"),
                ':updated_at' => $snippet['updated_at'] ?? gmdate("Y-m-d\TH:i:s\Z")
            ]);
            echo "Processed snippet (Title: {$title}, ModCode: {$generatedModificationCode})\n";
            $count++;
        } catch (PDOException $e) {
            echo "Error processing snippet (Title: '{$title}'): " . $e->getMessage() . "\n";
        }
    }

    echo "Seeding complete. {$count} records processed.\n";

} catch (PDOException $e) {
    die("Database connection or query failed: " . $e->getMessage() . "\n");
}

?>
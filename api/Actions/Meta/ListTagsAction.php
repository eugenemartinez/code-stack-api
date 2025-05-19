<?php

namespace App\Actions\Meta;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PDO;

class ListTagsAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            // Unnest the tags array, then select distinct non-empty tags
            // For PostgreSQL, UNNEST is appropriate.
            // The original query was:
            // "SELECT DISTINCT unnested_tag FROM code_snippets, UNNEST(tags) AS unnested_tag WHERE unnested_tag IS NOT NULL AND unnested_tag <> '' ORDER BY unnested_tag ASC"
            // This assumes 'tags' is a PostgreSQL array type (e.g., TEXT[]).
            // If 'tags' is JSONB, the query would be different (e.g., using jsonb_array_elements_text).
            // Assuming TEXT[] as per previous discussions on tag storage.
            
            // The query to unnest and get distinct tags from a TEXT[] column:
            $sql = "SELECT DISTINCT unnest(tags) AS tag 
                    FROM code_snippets 
                    WHERE tags IS NOT NULL AND CARDINALITY(tags) > 0 -- Ensure tags array is not null and not empty
                    ORDER BY tag ASC";
            
            // A slightly more robust version that also handles individual empty strings within the array if they can exist
            // and ensures the unnested tag itself is not an empty string.
             $sql = "SELECT DISTINCT t.tag
                    FROM code_snippets cs, UNNEST(cs.tags) AS t(tag)
                    WHERE t.tag IS NOT NULL AND t.tag <> ''
                    ORDER BY t.tag ASC";

            $stmt = $this->db->query($sql);
            $tags = $stmt->fetchAll(PDO::FETCH_COLUMN, 0); // Fetch as a flat array of tags

            $response->getBody()->write(json_encode($tags));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\PDOException $e) {
            error_log("Error in ListTagsAction: " . $e->getMessage());
            $errorPayload = ['error' => 'Failed to retrieve tags', 'details' => $e->getMessage()];
            $response->getBody()->write(json_encode($errorPayload));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
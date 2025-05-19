<?php

// Declare the namespace if you plan to use PSR-4 autoloading later,
// otherwise, it can be omitted if it's directly included/required.
// For simplicity with direct placement in /api, we might omit it for now
// or use a simple one like:
// namespace App\Middleware; 

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;
use Predis\Client as RedisClient; // Assuming Predis

class RateLimitMiddleware
{
    private static array $inMemoryRequestCounts = []; // Fallback in-memory store
    private ?RedisClient $redis;
    private int $limit;
    private int $windowSeconds; // For Redis, this is the TTL

    /**
     * @param int $limit Maximum number of requests allowed.
     * @param int $windowSeconds Time window in seconds (e.g., 86400 for a day).
     * @param ?RedisClient $redis Optional Redis client instance.
     */
    public function __construct(int $limit = 50, int $windowSeconds = 86400, ?RedisClient $redis = null)
    {
        $this->limit = $limit;
        $this->windowSeconds = $windowSeconds;
        $this->redis = $redis;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $ipAddress = $this->getClientIp($request);

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $isCudSnippetRoute = preg_match('/^\/api\/snippets(\/.*)?$/', $path) && in_array($method, ['POST', 'PUT', 'DELETE']);

        if (!$ipAddress || !$isCudSnippetRoute) {
            return $handler->handle($request); // Not a target route or no IP, skip rate limiting
        }

        $key = "ratelimit:" . $ipAddress . ":" . date('Y-m-d'); // Daily key

        $currentCount = 0;
        $isRateLimited = false;

        if ($this->redis) {
            try {
                $currentCount = (int)$this->redis->get($key);
                if ($currentCount === null || $currentCount === 0) { // Key might not exist or might be 0 from a previous error
                    $this->redis->incr($key);
                    $this->redis->expire($key, $this->windowSeconds); // Set expiry on first request of the day
                    $currentCount = 1;
                } else {
                    $currentCount = $this->redis->incr($key);
                }
                
                if ($currentCount > $this->limit) {
                    $isRateLimited = true;
                }
            } catch (\Predis\Connection\ConnectionException $e) {
                // Redis connection failed, log error and potentially fall back or allow request
                // For simplicity here, we'll fall through to in-memory if Redis fails
                // In production, you might want to log this error prominently.
                error_log("Redis connection failed for rate limiting: " . $e->getMessage());
                $this->redis = null; // Prevent further Redis attempts for this request
            }
        }
        
        // Fallback to in-memory if Redis is not available or failed
        if (!$this->redis) { 
            $inMemoryKey = $ipAddress . ':' . date('Y-m-d'); // Use a date-specific key for in-memory too
            if (!isset(self::$inMemoryRequestCounts[$inMemoryKey])) {
                self::$inMemoryRequestCounts[$inMemoryKey] = 0;
            }
            self::$inMemoryRequestCounts[$inMemoryKey]++;
            $currentCount = self::$inMemoryRequestCounts[$inMemoryKey];

            if ($currentCount > $this->limit) {
                $isRateLimited = true;
            }
        }


        if ($isRateLimited) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode([
                'error' => 'Too Many Requests',
                'message' => 'You have exceeded the ' . $this->limit . ' requests limit per day for this operation.'
            ]));
            // Optionally add Retry-After header
            // $response = $response->withHeader('Retry-After', (string)$this->windowSeconds); // Or calculate remaining time
            return $response->withHeader('Content-Type', 'application/json')->withStatus(429);
        }

        return $handler->handle($request);
    }

    private function getClientIp(Request $request): ?string
    {
        $serverParams = $request->getServerParams();
        $ipHeaders = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        foreach ($ipHeaders as $header) {
            if (!empty($serverParams[$header])) {
                $ips = explode(',', $serverParams[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $serverParams['REMOTE_ADDR'] ?? null; // Fallback to REMOTE_ADDR directly
    }
}
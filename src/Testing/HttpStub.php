<?php

declare(strict_types=1);

namespace Supaship\Testing;

use JsonException;

/**
 * Builds {@see SupashipClient} networkConfig httpHandlers for unit tests (no real HTTP).
 */
final class HttpStub
{
    /**
     * Handler that returns a successful Edge-shaped JSON body.
     *
     * @param array<string, mixed> $variations feature name => variation value (Edge: features[name].variation)
     */
    public static function success(array $variations = [], int $statusCode = 200): \Closure
    {
        $features = [];
        foreach ($variations as $name => $value) {
            $features[$name] = ['variation' => $value];
        }

        try {
            $json = json_encode(['features' => $features], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        return static function (string $url, string $jsonBody) use ($statusCode, $json): array {
            return ['statusCode' => $statusCode, 'body' => $json];
        };
    }

    /**
     * Handler that returns a non-2xx status or arbitrary body (simulates errors).
     */
    public static function failure(int $statusCode = 500, string $body = ''): \Closure
    {
        return static function (string $url, string $jsonBody) use ($statusCode, $body): array {
            return ['statusCode' => $statusCode, 'body' => $body];
        };
    }
}

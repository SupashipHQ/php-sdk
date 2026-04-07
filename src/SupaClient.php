<?php

declare(strict_types=1);

namespace Supaship;

use JsonException;

/**
 * Evaluates feature flags against Supaship Edge. Behavior aligns with @supaship/javascript-sdk:
 * POST JSON { environment, features, context }, Bearer sdkKey, response { features: { name: { variation } } }.
 *
 * On network or HTTP errors, returns configured fallback values (or rethrows when requesting zero features).
 */
final class SupaClient
{
    private string $sdkKey;

    private string $environment;

    /** @var array<string, mixed> */
    private array $featureDefinitions;

    /** @var array<string, string|int|float|bool|null>|null */
    private ?array $defaultContext;

    /** @var list<string> */
    private array $sensitiveContextProperties;

    private string $featuresAPIUrl;

    /** @var array{enabled: bool, maxAttempts: int, backoff: int} */
    private array $retry;

    private int $requestTimeoutMs;

    /**
     * Optional HTTP transport (e.g. tests or custom stacks). Same role as fetchFn in the JavaScript SDK.
     *
     * @var (callable(string $url, string $jsonBody): array{statusCode: int, body: string})|null
     */
    private $httpHandler;

    /**
     * @param array{
     *     sdkKey: string,
     *     environment: string,
     *     features: array<string, mixed>,
     *     context: array<string, string|int|float|bool|null>,
     *     sensitiveContextProperties?: list<string>,
     *     networkConfig?: array{
     *         featuresAPIUrl?: string,
     *         eventsAPIUrl?: string,
     *         retry?: array{enabled?: bool, maxAttempts?: int, backoff?: int},
     *         requestTimeoutMs?: int,
     *         httpHandler?: callable(string, string): array{statusCode: int, body: string}
     *     }
     * } $config
     */
    public function __construct(array $config)
    {
        foreach (['sdkKey', 'environment', 'features', 'context'] as $key) {
            if (!array_key_exists($key, $config)) {
                throw new \InvalidArgumentException("Missing required config key: {$key}");
            }
        }

        $this->sdkKey = $config['sdkKey'];
        $this->environment = $config['environment'];
        $this->featureDefinitions = $config['features'];
        $this->defaultContext = $config['context'];
        $this->sensitiveContextProperties = $config['sensitiveContextProperties'] ?? [];

        $net = $config['networkConfig'] ?? [];
        $this->featuresAPIUrl = $net['featuresAPIUrl'] ?? Constants::DEFAULT_FEATURES_URL;
        $retry = $net['retry'] ?? [];
        $this->retry = [
            'enabled' => $retry['enabled'] ?? true,
            'maxAttempts' => max(1, (int) ($retry['maxAttempts'] ?? 3)),
            'backoff' => max(0, (int) ($retry['backoff'] ?? 1000)),
        ];
        $this->requestTimeoutMs = max(0, (int) ($net['requestTimeoutMs'] ?? 10_000));
        $handler = $net['httpHandler'] ?? null;
        $this->httpHandler = is_callable($handler) ? $handler : null;
    }

    /**
     * @param array<string, string|int|float|bool|null> $context
     */
    public function updateContext(array $context, bool $mergeWithExisting = true): void
    {
        if ($mergeWithExisting && $this->defaultContext !== null) {
            $this->defaultContext = array_merge($this->defaultContext, $context);
        } else {
            $this->defaultContext = $context;
        }
    }

    /**
     * @return array<string, string|int|float|bool|null>|null
     */
    public function getContext(): ?array
    {
        return $this->defaultContext;
    }

    /**
     * @return mixed
     */
    public function getFeatureFallback(string $featureName)
    {
        return $this->featureDefinitions[$featureName] ?? null;
    }

    /**
     * @param array{context?: array<string, string|int|float|bool|null>|null} $options
     * @return mixed
     */
    public function getFeature(string $featureName, array $options = [])
    {
        try {
            $batch = $this->getFeatures([$featureName], $options);

            return $batch[$featureName];
        } catch (\Throwable $e) {
            return $this->getFeatureFallback($featureName);
        }
    }

    /**
     * @param list<string> $featureNames
     * @param array{context?: array<string, string|int|float|bool|null>|null} $options
     * @return array<string, mixed>
     */
    public function getFeatures(array $featureNames, array $options = []): array
    {
        $override = array_key_exists('context', $options) ? $options['context'] : null;
        $mergedContext = $this->resolveMergedContext($override);

        $featureNames = array_values($featureNames);

        try {
            $decoded = $this->retry['enabled']
                ? $this->withRetry(fn (): array => $this->fetchFeaturesFromNetwork($featureNames, $mergedContext))
                : $this->fetchFeaturesFromNetwork($featureNames, $mergedContext);

            return $this->mapVariationsToResult($featureNames, $decoded);
        } catch (\Throwable $e) {
            if ($featureNames === []) {
                throw $e;
            }

            return $this->fallbackResult($featureNames);
        }
    }

    /**
     * @param array<string, string|int|float|bool|null>|null $override
     * @return array<string, string|int|float|bool|null>|null
     */
    /**
     * @param mixed $override
     */
    private function resolveMergedContext($override): ?array
    {
        if (!is_array($override)) {
            return $this->defaultContext;
        }

        $base = $this->defaultContext ?? [];

        return array_merge($base, $override);
    }

    /**
     * @param array<string, string|int|float|bool|null>|null $context
     * @return array<string, string|int|float|bool|null>|null
     */
    private function hashSensitiveContext(?array $context): ?array
    {
        if ($context === null || $this->sensitiveContextProperties === []) {
            return $context;
        }

        $out = $context;
        foreach ($this->sensitiveContextProperties as $key) {
            if (!array_key_exists($key, $out)) {
                continue;
            }
            $value = $out[$key];
            if ($value === null) {
                continue;
            }
            $out[$key] = hash('sha256', (string) $value);
        }

        return $out;
    }

    /**
     * @param list<string> $featureNames
     * @param array<string, string|int|float|bool|null>|null $mergedContext
     * @return array<string, mixed>
     */
    private function fetchFeaturesFromNetwork(array $featureNames, ?array $mergedContext): array
    {
        $body = [
            'environment' => $this->environment,
            'features' => $featureNames,
            'context' => $this->hashSensitiveContext($mergedContext),
        ];

        try {
            $json = json_encode($body, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \RuntimeException('Failed to encode request: ' . $e->getMessage(), 0, $e);
        }

        if ($this->httpHandler !== null) {
            $response = ($this->httpHandler)($this->featuresAPIUrl, $json);
            $status = (int) ($response['statusCode'] ?? 0);
            $raw = (string) ($response['body'] ?? '');
        } else {
            $timeoutSec = $this->requestTimeoutMs > 0 ? $this->requestTimeoutMs / 1000.0 : 60.0;

            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $this->sdkKey,
                    ]),
                    'content' => $json,
                    'timeout' => $timeoutSec,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            /** @var array<int, string>|null $http_response_header */
            $http_response_header = null;
            $raw = @file_get_contents($this->featuresAPIUrl, false, $ctx);

            if ($raw === false) {
                throw new \RuntimeException('Failed to fetch features: transport error');
            }

            $status = $this->parseHttpStatusFromHeaders($http_response_header ?? []);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Failed to fetch features: HTTP ' . (string) $status);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \RuntimeException('Invalid JSON in features response: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data) || !isset($data['features']) || !is_array($data['features'])) {
            throw new \RuntimeException('Invalid features response shape');
        }

        return $data;
    }

    /**
     * @param list<string> $headers
     */
    private function parseHttpStatusFromHeaders(array $headers): int
    {
        if ($headers === [] || !isset($headers[0]) || !is_string($headers[0])) {
            return 0;
        }
        if (preg_match('{HTTP/\S+\s+(\d+)}', $headers[0], $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * @param callable(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function withRetry(callable $operation): array
    {
        $last = null;
        $max = $this->retry['maxAttempts'];
        $backoffMs = $this->retry['backoff'];

        for ($attempt = 1; $attempt <= $max; ++$attempt) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                $last = $e;
                if ($attempt >= $max) {
                    break;
                }
                $delayMs = (int) ($backoffMs * (2 ** ($attempt - 1)));
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }

        if ($last instanceof \Throwable) {
            throw $last;
        }

        throw new \RuntimeException('Request failed');
    }

    /**
     * @param list<string> $featureNames
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function mapVariationsToResult(array $featureNames, array $data): array
    {
        /** @var array<string, array<string, mixed>> $features */
        $features = $data['features'];
        $result = [];

        foreach ($featureNames as $name) {
            $entry = $features[$name] ?? [];
            $variation = is_array($entry) && array_key_exists('variation', $entry) ? $entry['variation'] : null;
            $fallback = $this->featureDefinitions[$name] ?? null;
            $result[$name] = $this->coerceVariation($variation, $fallback);
        }

        return $result;
    }

    /**
     * @param list<string> $featureNames
     * @return array<string, mixed>
     */
    private function fallbackResult(array $featureNames): array
    {
        $out = [];
        foreach ($featureNames as $name) {
            $out[$name] = $this->featureDefinitions[$name] ?? null;
        }

        return $out;
    }

    /**
     * @param mixed $variation
     * @param mixed $fallback
     * @return mixed
     */
    private function coerceVariation($variation, $fallback)
    {
        if ($variation !== null) {
            return $variation;
        }

        return $fallback ?? null;
    }
}

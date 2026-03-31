<?php

declare(strict_types=1);

namespace Supaship\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Supaship\Constants;
use Supaship\SupaClient;

final class SupaClientTest extends TestCase
{
    private const SDK_KEY = 'test-sdk-key';

    /** @return array<string, mixed> */
    private function baseFeatures(): array
    {
        return [
            'testFeature' => null,
            'feature1' => false,
            'feature2' => true,
        ];
    }

    /**
     * @param array<string, mixed> $networkExtras
     */
    private function makeClient(
        callable $handler,
        array $networkExtras = [],
        ?array $features = null,
        array $context = [],
        array $configExtras = []
    ): SupaClient {
        $network = array_merge(
            [
                'featuresAPIUrl' => 'https://example.test/features',
                'httpHandler' => $handler,
            ],
            $networkExtras
        );

        $base = [
            'sdkKey' => self::SDK_KEY,
            'environment' => 'test-env',
            'features' => $features ?? $this->baseFeatures(),
            'context' => $context,
            'networkConfig' => $network,
        ];

        return new SupaClient(array_merge($base, $configExtras));
    }

    public function testConstructorThrowsWhenRequiredKeyMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sdkKey');

        new SupaClient([
            'environment' => 'x',
            'features' => [],
            'context' => [],
        ]);
    }

    public function testDefaultNetworkOptionsMatchJsSdk(): void
    {
        $client = new SupaClient([
            'sdkKey' => 'k',
            'environment' => 'e',
            'features' => ['a' => false],
            'context' => [],
        ]);

        $ref = new ReflectionClass($client);
        $featuresUrl = $ref->getProperty('featuresAPIUrl');
        $featuresUrl->setAccessible(true);
        $retry = $ref->getProperty('retry');
        $retry->setAccessible(true);
        $timeout = $ref->getProperty('requestTimeoutMs');
        $timeout->setAccessible(true);

        self::assertSame(Constants::DEFAULT_FEATURES_URL, $featuresUrl->getValue($client));
        self::assertSame(
            ['enabled' => true, 'maxAttempts' => 3, 'backoff' => 1000],
            $retry->getValue($client)
        );
        self::assertSame(10_000, $timeout->getValue($client));
    }

    public function testCustomRetryConfiguration(): void
    {
        $client = new SupaClient([
            'sdkKey' => 'k',
            'environment' => 'e',
            'features' => ['a' => false],
            'context' => [],
            'networkConfig' => [
                'retry' => ['enabled' => false, 'maxAttempts' => 5, 'backoff' => 2000],
            ],
        ]);

        $ref = new ReflectionClass($client);
        $retry = $ref->getProperty('retry');
        $retry->setAccessible(true);

        self::assertSame(
            ['enabled' => false, 'maxAttempts' => 5, 'backoff' => 2000],
            $retry->getValue($client)
        );
    }

    public function testUpdateContextMergesByDefault(): void
    {
        $client = $this->makeClient(static fn (): array => ['statusCode' => 200, 'body' => '{}'], [], null, [
            'existing' => 'value',
            'toUpdate' => 'old',
        ]);

        $client->updateContext(['toUpdate' => 'new', 'newKey' => 'newValue']);

        self::assertSame(
            ['existing' => 'value', 'toUpdate' => 'new', 'newKey' => 'newValue'],
            $client->getContext()
        );
    }

    public function testUpdateContextReplacesWhenMergeDisabled(): void
    {
        $client = $this->makeClient(static fn (): array => ['statusCode' => 200, 'body' => '{}'], [], null, [
            'existing' => 'value',
        ]);

        $client->updateContext(['only' => 'this'], false);

        self::assertSame(['only' => 'this'], $client->getContext());
    }

    public function testGetFeatureFallback(): void
    {
        $client = $this->makeClient(static fn (): array => ['statusCode' => 200, 'body' => '{}']);

        self::assertFalse($client->getFeatureFallback('feature1'));
        self::assertNull($client->getFeatureFallback('unknown'));
    }

    public function testGetFeaturesReturnsVariations(): void
    {
        $body = json_encode([
            'features' => [
                'feature1' => ['variation' => true],
                'feature2' => ['variation' => false],
            ],
        ], JSON_THROW_ON_ERROR);

        $client = $this->makeClient(static function (string $url, string $json) use ($body): array {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['feature1', 'feature2'], $decoded['features']);
            self::assertSame('test-env', $decoded['environment']);

            return ['statusCode' => 200, 'body' => $body];
        });

        $result = $client->getFeatures(['feature1', 'feature2']);

        self::assertSame(['feature1' => true, 'feature2' => false], $result);
    }

    public function testGetFeatureUsesGetFeaturesAndReturnsTypedValue(): void
    {
        $payload = json_encode([
            'features' => ['testFeature' => ['variation' => true]],
        ], JSON_THROW_ON_ERROR);

        $client = $this->makeClient(static fn (): array => ['statusCode' => 200, 'body' => $payload]);

        self::assertTrue($client->getFeature('testFeature'));
    }

    public function testGetFeaturesUsesFallbackWhenHttpError(): void
    {
        $client = $this->makeClient(static fn (): array => [
            'statusCode' => 500,
            'body' => 'error',
        ]);

        self::assertSame(['feature1' => false, 'feature2' => true], $client->getFeatures(['feature1', 'feature2']));
    }

    public function testGetFeaturesThrowsWhenEmptyRequestAndHttpFails(): void
    {
        $client = $this->makeClient(static fn (): array => ['statusCode' => 500, 'body' => '']);

        $this->expectException(\RuntimeException::class);

        $client->getFeatures([]);
    }

    public function testGetFeatureReturnsFallbackWhenUnderlyingRequestFails(): void
    {
        $client = $this->makeClient(static fn (): array => ['statusCode' => 500, 'body' => '']);

        self::assertFalse($client->getFeature('feature1'));
    }

    public function testMissingVariationFallsBackToDefinition(): void
    {
        $payload = json_encode(['features' => ['testFeature' => []]], JSON_THROW_ON_ERROR);

        $client = $this->makeClient(static fn (): array => ['statusCode' => 200, 'body' => $payload]);

        self::assertNull($client->getFeature('testFeature'));
    }

    public function testSensitiveContextPropertiesAreHashedInRequestBody(): void
    {
        $client = $this->makeClient(
            static function (string $url, string $json): array {
                $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                $expectedEmailHash = hash('sha256', 'user@example.com');

                self::assertSame([
                    'email' => $expectedEmailHash,
                    'plan' => 'pro',
                ], $decoded['context']);

                return [
                    'statusCode' => 200,
                    'body' => json_encode(['features' => ['feature1' => ['variation' => true]]], JSON_THROW_ON_ERROR),
                ];
            },
            [],
            ['feature1' => false],
            [
                'email' => 'user@example.com',
                'plan' => 'pro',
            ],
            [
                'sensitiveContextProperties' => ['email'],
            ]
        );

        $client->getFeatures(['feature1']);
    }

    public function testPerRequestContextIsMergedIntoPayload(): void
    {
        $client = $this->makeClient(
            static function (string $url, string $json): array {
                $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                self::assertSame([
                    'default' => 'ctx',
                    'request' => 'override',
                ], $decoded['context']);

                return [
                    'statusCode' => 200,
                    'body' => json_encode(['features' => ['f' => ['variation' => 1]]], JSON_THROW_ON_ERROR),
                ];
            },
            [],
            ['f' => null],
            ['default' => 'ctx']
        );

        $client->getFeatures(['f'], ['context' => ['request' => 'override']]);
    }

    public function testRetryEventuallySucceeds(): void
    {
        $ok = json_encode(['features' => ['feature1' => ['variation' => true]]], JSON_THROW_ON_ERROR);
        $attempts = 0;

        $client = $this->makeClient(
            static function () use ($ok, &$attempts): array {
                ++$attempts;
                if ($attempts < 3) {
                    return ['statusCode' => 503, 'body' => ''];
                }

                return ['statusCode' => 200, 'body' => $ok];
            },
            [
                'retry' => ['enabled' => true, 'maxAttempts' => 3, 'backoff' => 0],
            ]
        );

        $result = $client->getFeatures(['feature1']);

        self::assertSame(['feature1' => true], $result);
        // phpcs:ignore Generic.Files.LineLength
        self::assertSame(3, $attempts, 'Should only succeed on the third attempt.');
    }

    public function testRetryDisabledFailsOnFirstError(): void
    {
        $client = $this->makeClient(
            static fn (): array => ['statusCode' => 503, 'body' => ''],
            [
                'retry' => ['enabled' => false, 'maxAttempts' => 3, 'backoff' => 0],
            ]
        );

        $result = $client->getFeatures(['feature1']);

        self::assertSame(['feature1' => false], $result);
    }

    public function testInvalidJsonResponseFallsBack(): void
    {
        $client = $this->makeClient(static fn (): array => ['statusCode' => 200, 'body' => 'not-json']);

        self::assertNull($client->getFeature('testFeature'));
    }

    public function testInvalidResponseShapeFallsBack(): void
    {
        $client = $this->makeClient(static fn (): array => [
            'statusCode' => 200,
            'body' => json_encode(['unexpected' => true], JSON_THROW_ON_ERROR),
        ]);

        self::assertFalse($client->getFeature('feature1'));
    }
}

<?php

declare(strict_types=1);

namespace Supaship\Tests;

use PHPUnit\Framework\TestCase;
use Supaship\SupashipClient;
use Supaship\Testing\HttpStub;

final class HttpStubTest extends TestCase
{
    public function testSuccessHandlerReturnsExpectedPayload(): void
    {
        $handler = HttpStub::success(['flag' => true]);
        $decoded = json_decode($handler('', '{}')['body'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['flag' => ['variation' => true]], $decoded['features']);
    }

    public function testClientUsesStubWithoutNetwork(): void
    {
        $client = new SupashipClient([
            'sdkKey' => 'test',
            'environment' => 'test',
            'features' => ['x' => false],
            'context' => [],
            'networkConfig' => [
                'featuresAPIUrl' => 'https://test.local/features',
                'httpHandler' => HttpStub::success(['x' => true]),
            ],
        ]);

        self::assertTrue($client->getFeature('x'));
    }
}

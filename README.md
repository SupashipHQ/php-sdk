# Supaship PHP SDK

Lightweight, zero-dependency (runtime) client for [Supaship](https://supaship.com) feature flags. The API mirrors [@supashiphq/javascript-sdk](https://www.npmjs.com/package/@supashiphq/javascript-sdk): same config shape, POST body, and fallback behavior.

## Requirements

- PHP 8.1+
- Extensions: `json`, `openssl` (for `https://` to Edge)

## Install

```bash
composer require supashiphq/php-sdk
```

## Quick start

```php
<?php

use Supaship\SupaClient;

$features = [
    'new-ui' => false,
    'theme-config' => [
        'primaryColor' => '#007bff',
        'darkMode' => false,
    ],
];

$client = new SupaClient([
    'sdkKey' => getenv('SUPASHIP_SDK_KEY'),
    'environment' => 'production',
    'features' => $features,
    'context' => [
        'userId' => '123',
        'email' => 'user@example.com',
        'plan' => 'premium',
    ],
]);

$isNewUi = $client->getFeature('new-ui');
$theme = $client->getFeature('theme-config');

$batch = $client->getFeatures(['new-ui', 'theme-config'], [
    'context' => ['userId' => '456'],
]);
```

## Configuration

| Key | Required | Description |
|-----|----------|-------------|
| `sdkKey` | Yes | Project SDK key |
| `environment` | Yes | Environment slug (e.g. `production`) |
| `features` | Yes | Associative array of flag names → fallback values (boolean, array, or `null`) |
| `context` | Yes | Default evaluation context (scalar values only) |
| `sensitiveContextProperties` | No | List of context keys whose values are hashed with SHA-256 before the request |
| `networkConfig` | No | See below |

### `networkConfig`

| Key | Default | Description |
|-----|---------|-------------|
| `featuresAPIUrl` | `https://edge.supaship.com/v1/features` | Features endpoint |
| `eventsAPIUrl` | `https://edge.supaship.com/v1/events` | Documented for parity; not used by this minimal client |
| `requestTimeoutMs` | `10000` | Request timeout (milliseconds) |
| `retry` | `enabled: true`, `maxAttempts: 3`, `backoff: 1000` | Exponential backoff in ms, same as the JS SDK |
| `httpHandler` | *(none)* | Optional `callable(string $url, string $jsonBody): array{statusCode:int, body:string}` |

## Methods

- `getFeature(string $name, array $options = []): mixed` — single flag; on failure returns the configured fallback (never throws for network errors).
- `getFeatures(array $names, array $options = []): array` — batch evaluation; on failure returns fallbacks for all requested names. If `$names` is empty and the request fails, throws.
- `updateContext(array $context, bool $mergeWithExisting = true): void`
- `getContext(): ?array`
- `getFeatureFallback(string $name): mixed`

Optional per-request context:

```php
$client->getFeature('new-ui', ['context' => ['plan' => 'enterprise']]);
```

## Constants

`Supaship\Constants::DEFAULT_FEATURES_URL` and `DEFAULT_EVENTS_URL` match the JavaScript SDK defaults.

## Advanced: `httpHandler`

For custom HTTP stacks or tests, you can inject a handler (same idea as `fetchFn` in the JavaScript SDK):

```php
$client = new SupaClient([
    'sdkKey' => '...',
    'environment' => 'production',
    'features' => $features,
    'context' => [],
    'networkConfig' => [
        'httpHandler' => static function (string $url, string $jsonBody): array {
            return ['statusCode' => 200, 'body' => '{"features":{}}'];
        },
    ],
]);
```

The handler must return `['statusCode' => int, 'body' => string]` where `body` is the raw JSON response.

## Developing & tests

```bash
composer install
composer test
```

Maintainers: see **[DEPLOY.md](DEPLOY.md)** for registering the package on Packagist, webhooks, and version tags.

## License

MIT — see [LICENSE](LICENSE).

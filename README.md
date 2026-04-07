# Supaship PHP SDK

Lightweight, zero-dependency (runtime) client for [Supaship](https://supaship.com) feature flags.

## Requirements

- PHP 7.4+
- Extensions: `json`, `openssl` (for `https://` to Edge)

The repo’s `composer.json` sets `config.platform.php` to **7.4.33** so **`composer.lock`** stays installable on PHP 7.4 (transitive dev tools). That does not affect apps that `composer require` this library; only this package’s root install uses it.

## Install

```bash
composer require supaship/php-sdk
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

## Framework integrations

`SupaClient` has no framework-specific code paths: register it once in the container (or a bootstrap file), inject it where you need flags, and call `getFeature` / `getFeatures`. All three examples assume `composer require supaship/php-sdk` is already done.

The **SDK** supports **PHP 7.4+**. **Laravel examples** below use **PHP 8.1+** syntax (e.g. `readonly` constructor promotion). For Symfony, adjust to your version’s PHP requirement.

Evaluations are **synchronous** (each call waits for the HTTP response unless you wrap them yourself, e.g. queue or async jobs).

---

### Laravel

**1. Environment**

In `.env`:

```env
SUPASHIP_SDK_KEY=your-sdk-key
SUPASHIP_ENVIRONMENT=production
```

**2. Config file** (e.g. `config/supaship.php`)

```php
<?php

return [
    'sdk_key' => env('SUPASHIP_SDK_KEY'),
    'environment' => env('SUPASHIP_ENVIRONMENT', 'production'),
    /**
     * Central list of flags and fallbacks — keep in sync with what you use in Supaship.
     */
    'features' => [
        'new-ui' => false,
        'theme-config' => [
            'primaryColor' => '#007bff',
            'darkMode' => false,
        ],
    ],
];
```

**3. Register the client** in `app/Providers/AppServiceProvider.php` (method `register()`):

```php
use Illuminate\Support\ServiceProvider;
use Supaship\SupaClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SupaClient::class, function ($app) {
            $config = $app['config']->get('supaship');

            return new SupaClient([
                'sdkKey' => $config['sdk_key'],
                'environment' => $config['environment'],
                'features' => $config['features'],
                'context' => [
                    // Filled at boot or request time — see below
                    'appEnv' => config('app.env'),
                ],
            ]);
        });
    }
}
```

**4. Per-request context** (e.g. after auth)

In `AppServiceProvider::boot()` or a middleware:

```php
use Illuminate\Support\Facades\Auth;
use Supaship\SupaClient;

public function boot(): void
{
    $this->app->afterResolving(SupaClient::class, function (SupaClient $client) {
        $user = Auth::user();
        if ($user) {
            $client->updateContext([
                'userId' => (string) $user->id,
                'email' => $user->email ?? '',
            ]);
        }
    });
}
```

**5. Use in a controller**

```php
use Supaship\SupaClient;

class DashboardController extends Controller
{
    public function __construct(private readonly SupaClient $features) {}

    public function index()
    {
        $showNewUi = $this->features->getFeature('new-ui');
        $theme = $this->features->getFeature('theme-config');

        return view('dashboard', compact('showNewUi', 'theme'));
    }
}
```

Optional **middleware** that only refreshes context is often cleaner than `afterResolving` when you need `Auth::user()` on every request.

---

### Symfony

**1. Environment**

In `.env.local` (do not commit secrets):

```env
SUPASHIP_SDK_KEY=your-sdk-key
SUPASHIP_ENVIRONMENT=production
```

**2. Parameters** in `config/services.yaml` (Symfony 6/7 style):

```yaml
parameters:
    supaship.sdk_key: '%env(SUPASHIP_SDK_KEY)%'
    supaship.environment: '%env(SUPASHIP_ENVIRONMENT)%'
    supaship.features:
        new-ui: false
        theme-config:
            primaryColor: '#007bff'
            darkMode: false
```

For larger `features` maps, you can load a dedicated file with `imports:` or define the array in PHP via a small config class; the important part is passing the same structure into `SupaClient`.

**3. Service definition** in `config/services.yaml`:

```yaml
services:
    Supaship\SupaClient:
        class: Supaship\SupaClient
        arguments:
            - {
                  sdkKey: '%supaship.sdk_key%',
                  environment: '%supaship.environment%',
                  features: '%supaship.features%',
                  context: { appEnv: '%kernel.environment%' }
              }
        public: true
```

**4. Subscriber** to attach the current user to the client (example using Symfony security):

```php
<?php

namespace App\EventSubscriber;

use Supaship\SupaClient;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SupashipContextSubscriber implements EventSubscriberInterface
{
    /** @var SupaClient */
    private $client;

    /** @var Security */
    private $security;

    public function __construct(SupaClient $client, Security $security)
    {
        $this->client = $client;
        $this->security = $security;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if ($user === null) {
            return;
        }

        $this->client->updateContext([
            // Adjust to your User class / identifier field
            'userId' => method_exists($user, 'getUserIdentifier')
                ? $user->getUserIdentifier()
                : (string) spl_object_id($user),
        ]);
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 8]];
    }
}
```

Register the subscriber (Symfony auto-wires if `App\` is configured; otherwise add explicit service tags for `kernel.event_subscriber`).

**5. Controller**

```php
use Supaship\SupaClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(SupaClient $features): Response
    {
        $newUi = $features->getFeature('new-ui');

        return $this->render('home.html.twig', ['new_ui' => $newUi]);
    }
}
```

---

### CodeIgniter 4

**1. Environment**

In `.env`:

```env
supaship.sdkKey = "your-sdk-key"
supaship.environment = "production"
```

**2. Central feature map**

Create `app/Config/SupashipFeatures.php` (or keep the array inside `Services` if you prefer):

```php
<?php

namespace Config;

class SupashipFeatures
{
    /** @return array<string, mixed> */
    public static function fallbacks(): array
    {
        return [
            'new-ui' => false,
            'theme-config' => [
                'primaryColor' => '#007bff',
                'darkMode' => false,
            ],
        ];
    }
}
```

**3. Service registration** in `app/Config/Services.php`:

```php
<?php

namespace Config;

use CodeIgniter\Config\BaseService;
use Supaship\SupaClient;

class Services extends BaseService
{
    public static function supaship(bool $getShared = true): SupaClient
    {
        if ($getShared) {
            return static::getSharedInstance('supaship');
        }

        return new SupaClient([
            'sdkKey' => getenv('supaship.sdkKey') ?: '',
            'environment' => getenv('supaship.environment') ?: 'production',
            'features' => SupashipFeatures::fallbacks(),
            'context' => [
                'ciEnv' => ENVIRONMENT,
            ],
        ]);
    }
}
```

**4. Per-request context** — e.g. in a filter `app/Filters/SupashipContext.php`:

```php
<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

class SupashipContext implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $client = Services::supaship();
        $session = session();

        if ($session->get('userId')) {
            $client->updateContext([
                'userId' => (string) $session->get('userId'),
            ]);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
```

Register the filter in `app/Config/Filters.php` for the routes or groups that need targeting.

**5. Controller**

```php
<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use Config\Services;

class Home extends Controller
{
    public function index()
    {
        $features = Services::supaship();

        $data = [
            'new_ui' => $features->getFeature('new-ui'),
            'theme' => $features->getFeature('theme-config'),
        ];

        return view('home', $data);
    }
}
```

---

### Shared tips (all frameworks)

- Keep **`features`** (fallback map) in one file so it stays aligned with names in the Supaship dashboard.
- Use **`sensitiveContextProperties`** in the client config when passing emails or IDs you want hashed before they leave your server (same as the JavaScript SDK).
- Prefer **`getFeatures(['a','b','c'])`** for a single HTTP round-trip instead of many **`getFeature`** calls when loading a page.

## Testing

Unit tests should **not** call Supaship Edge. Pass an **`httpHandler`** under `networkConfig` (same hook as in [Advanced: `httpHandler`](#advanced-httphandler)) so `SupaClient` never opens a socket.

### `Supaship\Testing\HttpStub`

The package includes a tiny helper so you do not hand-build JSON for every test:

```php
use Supaship\SupaClient;
use Supaship\Testing\HttpStub;

$client = new SupaClient([
    'sdkKey' => 'test-key',
    'environment' => 'test',
    'features' => [
        'new-ui' => false,
        'theme-config' => ['darkMode' => false],
    ],
    'context' => ['userId' => '42'],
    'networkConfig' => [
        'httpHandler' => HttpStub::success([
            'new-ui' => true,
            'theme-config' => ['darkMode' => true, 'primaryColor' => '#111'],
        ]),
    ],
]);

$this->assertTrue($client->getFeature('new-ui'));
```

Simulate API or transport failures (client falls back to your configured defaults):

```php
'networkConfig' => [
    'httpHandler' => HttpStub::failure(503, 'unavailable'),
],
```

### Laravel: testing a route that injects `SupaClient`

Your app resolves `SupaClient` from the container (e.g. `AppServiceProvider` registers a **singleton**). In a feature test you **swap** that binding for a client wired with **`HttpStub`**, then call the route. Laravel will inject your test double instead of the real client.

Assume this route (simplified):

```php
Route::get('/', function (SupaClient $client) {
    $isNewUi = $client->getFeature('cool-new-feature', ['context' => [
        'userId' => '123',
    ]]);

    return $isNewUi ? view('new-welcome') : view('welcome');
});
```

Use the **same `features` fallback map** shape as in production (at least the keys you request). Register a client whose `httpHandler` returns the flag value you want for that test:

```php
<?php

namespace Tests\Feature;

use Supaship\SupaClient;
use Supaship\Testing\HttpStub;
use Tests\TestCase;

class WelcomeRouteTest extends TestCase
{
    private function clientWithFlag(bool $coolNewFeatureEnabled): SupaClient
    {
        return new SupaClient([
            'sdkKey' => 'test',
            'environment' => 'testing',
            'features' => [
                'cool-new-feature' => false,
            ],
            'context' => [],
            'networkConfig' => [
                'httpHandler' => HttpStub::success([
                    'cool-new-feature' => $coolNewFeatureEnabled,
                ]),
            ],
        ]);
    }

    public function test_home_uses_welcome2_when_flag_is_true(): void
    {
        $this->app->instance(SupaClient::class, $this->clientWithFlag(true));

        $this->get('/')
            ->assertOk()
            ->assertViewIs('new-welcome');
    }

    public function test_home_uses_welcome_when_flag_is_false(): void
    {
        $this->app->instance(SupaClient::class, $this->clientWithFlag(false));

        $this->get('/')
            ->assertOk()
            ->assertViewIs('welcome');
    }
}
```

Why this works:

- **`$this->app->instance(SupaClient::class, …)`** tells Laravel: “when anything needs `SupaClient`, use this instance.” It runs **before** `$this->get('/')`, so the closure receives your stubbed client.
- **`HttpStub::success([...])`** simulates Edge returning that variation, so **`getFeature`** never performs a real HTTP request.
- To test **fallback** behavior (e.g. Edge down), use **`HttpStub::failure()`** and assert `welcome` if your fallback for `cool-new-feature` is false.

### Asserting what would be sent to Edge

The handler receives the POST URL and the **request body string** (JSON). Capture it in a closure when you care about `environment`, `features`, or `context`:

`json_decode`’s third parameter is the **maximum nesting depth**; **`512` is PHP’s default**. On PHP 7.4 you cannot use named arguments, so you pass that default explicitly whenever you need the fourth parameter (`JSON_THROW_ON_ERROR`). e.g. `json_decode($jsonBody, true, 512, JSON_THROW_ON_ERROR)`.

```php
$captured = null;

$client = new SupaClient([
    'sdkKey' => 'sk',
    'environment' => 'staging',
    'features' => ['promo' => false],
    'context' => ['region' => 'eu'],
    'networkConfig' => [
        'httpHandler' => function (string $url, string $jsonBody) use (&$captured) {
            $captured = json_decode($jsonBody, true, flags: JSON_THROW_ON_ERROR);

            return ['statusCode' => 200, 'body' => '{"features":{"promo":{"variation":true}}}'];
        },
    ],
]);

$client->getFeatures(['promo'], ['context' => ['plan' => 'pro']]);

$this->assertSame('staging', $captured['environment']);
$this->assertSame(['promo'], $captured['features']);
$this->assertSame(['region' => 'eu', 'plan' => 'pro'], $captured['context']);
```

### PHPUnit in your app

Add a dev dependency and point to your tests directory (typical `phpunit.xml.dist`):

```bash
composer require --dev phpunit/phpunit
```

Then run:

```bash
vendor/bin/phpunit
```

The SDK’s own test suite is `composer test` from a clone of this repository (`vendor/bin/phpunit` after `composer install`).

## Constants

`Supaship\Constants::DEFAULT_FEATURES_URL` and `DEFAULT_EVENTS_URL` match the JavaScript SDK defaults.

## Advanced: `httpHandler`

For **production** custom HTTP (proxy, corporate CA, tracing), or ad-hoc test doubles, inject a handler (same idea as `fetchFn` in the JavaScript SDK). For most unit tests, prefer **`HttpStub`** in the [Testing](#testing) section.

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

## Developing & tests (this repository)

```bash
composer install
composer test
```

This runs PHPUnit on `tests/`, including stub coverage for **`Supaship\Testing\HttpStub`**.

Maintainers: To ship a version from GitHub, use **Actions → Publish Supaship PHP SDK** (`publish.yml`): it validates, runs tests, and opens a GitHub Release with auto-generated notes from commits since the last release.

## License

MIT — see [LICENSE](LICENSE).

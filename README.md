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

## Framework integrations

`SupaClient` has no framework-specific code paths: register it once in the container (or a bootstrap file), inject it where you need flags, and call `getFeature` / `getFeatures`. All three examples assume `composer require supashiphq/php-sdk` is already done.

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
    public function __construct(
        private readonly SupaClient $client,
        private readonly Security $security,
    ) {}

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

Maintainers: To ship a version from GitHub, use **Actions → Publish Supaship PHP SDK** (`publish.yml`): it validates, runs tests, and opens a GitHub Release with auto-generated notes from commits since the last release.

## License

MIT — see [LICENSE](LICENSE).

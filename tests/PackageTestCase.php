<?php

namespace Sendtrap\Core\Tests;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Orchestra\Testbench\TestCase;
use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Contracts\UsageMeter;
use Sendtrap\Core\Contracts\WorkspaceAccess;
use Sendtrap\Core\Contracts\WorkspaceContext;
use Sendtrap\Core\Http\Middleware\AuthenticateInboxToken;
use Sendtrap\Core\SendtrapCoreServiceProvider;
use Sendtrap\Core\Support\HtmlCompatibility\CaniemailDataset;
use Sendtrap\Core\Tests\Fakes\AllowAllWorkspaceAccess;
use Sendtrap\Core\Tests\Fakes\FakeWorkspace;
use Sendtrap\Core\Tests\Fakes\SingleWorkspaceContext;
use Sendtrap\Core\Tests\Fakes\UnlimitedEntitlements;
use Sendtrap\Core\Tests\Fakes\UnlimitedUsageMeter;

/**
 * Base test case for the sendtrap/core package's own Testbench suite
 * ("package tests pass independently"). This is what
 * makes `vendor/bin/phpunit`, run from the package root, a real, standalone
 * Laravel application boot: Orchestra Testbench supplies the minimal test
 * host, this class wires the package's own provider and a set of trivial
 * reference-binding fakes into it.
 *
 * These bindings (WorkspaceContext/WorkspaceAccess/Entitlements/UsageMeter)
 * are test doubles living in `tests/Fakes/` — never the package's shipped
 * default implementations. The package intentionally ships no default for
 * any of the four; each host (Cloud today, Community later) binds its own.
 * Only `LegacyOwnershipFallback` (Plan 06 Phase 3b slice 3) gets a real
 * package-shipped default (`NullLegacyOwnershipFallback`), because its
 * whole purpose is being safely no-op-able.
 *
 * LOW-1 pin (§1.2's Inbox row / §5.3) — LAPSED as of slice 3:
 * `Inbox::effectiveAllowedIps()`'s last resort is now the
 * `LegacyOwnershipFallback` contract call, no longer the slice-2-verbatim
 * `$this->project?->team?->allowed_ips ?? []` chain that relied on a
 * missing `team` attribute degrading to `null` while Eloquent strict
 * missing-attribute mode is off. Enabling `Model::shouldBeStrict()` /
 * `preventAccessingMissingAttributes()` here is therefore no longer
 * forbidden by that pin — it just hasn't been adopted (a separate,
 * deliberate harness decision if a future slice wants it).
 */
abstract class PackageTestCase extends TestCase
{
    // RefreshDatabase (not Testbench's rollback-on-teardown MigrateProcessor
    // path): each test gets a freshly migrated in-memory sqlite DB and the
    // package migrations are never rolled back by the harness. This keeps
    // §7.2's "moved with zero content changes" promise intact — the harness
    // must not require every historical migration's down() to be
    // sqlite-rollback-clean when no host has ever rolled them back either.
    use RefreshDatabase;

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SendtrapCoreServiceProvider::class,

            // Plan 06 Phase 3 gate finding H-1: Sendtrap\Core\Http\
            // Controllers\ShareController/InboxShareController directly
            // `use Inertia\Inertia` (real, load-bearing code) — but
            // Orchestra\Testbench\TestCase::$enablesPackageDiscoveries
            // defaults to false (verified directly: composer's own
            // `vendor/bin/testbench package:discover` cache correctly lists
            // Inertia\ServiceProvider, yet app()->getProviders(Inertia\
            // ServiceProvider::class) was still empty until this line was
            // added), so the package's own Testbench harness never
            // auto-discovers ANY dependency's service provider, Inertia's
            // included, the way a real host's own full Laravel boot would.
            // Explicit registration here is what makes Inertia::render()'s
            // testing macros (Inertia\ServiceProvider::register() ->
            // registerTestingMacros() -> TestResponse::mixin(...)) actually
            // available to a package test — without it, Inertia::render()
            // still appears to "work" (the container auto-resolves the
            // unregistered concrete Inertia\ResponseFactory class on
            // demand), which is precisely how this gap stayed invisible: a
            // test asserting only "not an error" would pass either way.
            InertiaServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        // Plan 06 Phase 3b slice 2: the core-table migrations live in the
        // package now (§7) — the slice-1 is_dir guard is gone because the
        // directory is a permanent part of the package.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Inbox::smtpPassword() is an encrypted cast — the Testbench
        // skeleton ships no APP_KEY, so give the harness a throwaway one.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $workspace = new FakeWorkspace;

        $app->singleton(WorkspaceContext::class, fn () => new SingleWorkspaceContext($workspace));
        $app->singleton(WorkspaceAccess::class, fn () => new AllowAllWorkspaceAccess);
        $app->singleton(Entitlements::class, fn () => new UnlimitedEntitlements);
        $app->singleton(UsageMeter::class, fn () => new UnlimitedUsageMeter);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCaniemailFeaturesCache();
        $this->registerApiRateLimiters();
    }

    /**
     * Rate limiter *registration*
     * (`RateLimiter::for()`) has no package-level loading mechanism — it's a
     * per-host `boot()` call, so each host registers its own
     * copy from the resolution pattern the package documents.
     * `PackageTestCase` plays that "host" role for the package's own
     * Testbench suite — routes under `throttle:inbox-api`/
     * `throttle:inbox-api-wait` (routes/api.php)
     * 500 with a MissingRateLimiterException without this. Mirrors a real
     * host's registration exactly (the host's own service-provider
     * `boot()`); keep both in sync if that closure's behavior ever changes.
     */
    protected function registerApiRateLimiters(): void
    {
        RateLimiter::for('inbox-api', function (Request $request) {
            $inbox = AuthenticateInboxToken::resolve($request);

            $key = $inbox ? 'inbox:'.$inbox->id : 'ip:'.$request->ip();

            $workspace = $inbox?->project?->workspace;
            $perMinute = $workspace
                ? (app(Entitlements::class)->for($workspace)->apiRequestsPerMinute() ?? 300)
                : 60;

            return Limit::perMinute($perMinute)->by($key);
        });

        RateLimiter::for('inbox-api-wait', function (Request $request) {
            $key = $request->bearerToken() ?: $request->header('X-Api-Token') ?: $request->ip();

            return Limit::perMinute(15)->by($key);
        });
    }

    /**
     * `Sendtrap\Core\Support\HtmlCompatibility\CaniemailDataset::payload()`
     * reads the vendored caniemail dataset via `resource_path()` — correct
     * and unedited when booted inside a host
     * application, which genuinely has `resources/data/caniemail/features.json`.
     * This package's own Testbench skeleton app has no such file, and
     * `CaniemailDataset` must not be edited to know that (it must never
     * depend on where it happens to be running).
     *
     * `CaniemailDataset::features()` reads through `Cache::rememberForever()`
     * before ever touching `payload()`/`resource_path()` — so pre-warming
     * that cache key with the package's own vendored copy of the real
     * dataset (`tests/Fixtures/caniemail-features.json`, a field-trimmed
     * snapshot of the upstream caniemail dataset — unused per-feature
     * `test_results_url` fields are stripped, everything the code reads —
     * `slug`/`title`/`category`/`stats` and the top-level
     * `data`/`last_update_date` — is verbatim; see NOTICE for attribution)
     * short-circuits the
     * file read entirely, with zero edits to `CaniemailDataset` itself.
     * `CaniemailDataset::version()` bypasses the cache and would still hit
     * `resource_path()` directly
     * (MessageHtmlCheckResolutionTest is the first package-side test to
     * call `Message::resolveHtmlCheck()`, which reads `version()` on every
     * call, cache-warmed or not) — so this also reflectively seeds
     * `CaniemailDataset`'s private `$payload` static property directly —
     * the one piece `Cache::rememberForever()` can't intercept, since
     * `version()` reads `payload()` unconditionally, never through the
     * cache. Reflection rather than a new public testing-only setter on
     * `CaniemailDataset` itself, to avoid adding test-only surface area to
     * a class real hosts also use in production.
     */
    protected function seedCaniemailFeaturesCache(): void
    {
        $fixture = __DIR__.'/Fixtures/caniemail-features.json';

        if (! is_file($fixture)) {
            return;
        }

        $payload = json_decode(file_get_contents($fixture), true);

        Cache::forever(
            'htmlcheck:caniemail-features',
            collect($payload['data'])->keyBy('slug')->all(),
        );

        $payloadProperty = new \ReflectionProperty(CaniemailDataset::class, 'payload');
        $payloadProperty->setAccessible(true);
        $payloadProperty->setValue(null, $payload);
    }
}

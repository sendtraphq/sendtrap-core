<?php

namespace Sendtrap\Core;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Sendtrap\Core\Console\Commands\MigrateStorageToS3;
use Sendtrap\Core\Console\Commands\PruneMessages;
use Sendtrap\Core\Console\Commands\SmtpServer;
use Sendtrap\Core\Console\Commands\SyncCaniemailData;
use Sendtrap\Core\Contracts\LegacyOwnershipFallback;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Project;
use Sendtrap\Core\Policies\InboxPolicy;
use Sendtrap\Core\Policies\MessagePolicy;
use Sendtrap\Core\Policies\ProjectPolicy;
use Sendtrap\Core\Support\NullLegacyOwnershipFallback;

/**
 * Boots the public Sendtrap core package.
 *
 * Phase 3 progressively moves domain models/migrations, SMTP ingestion,
 * storage, API controllers/resources, events, checks and shared frontend
 * components into this package. As of Phase 3b slice 2 the package owns the
 * domain models (src/Models), their factories (database/factories) and the
 * core-table migrations (database/migrations). Host applications (Cloud
 * today, Community later) bind their own implementations of the package
 * contracts in their own service providers.
 *
 * MIGRATION OWNERSHIP RULE (Plan 06 Phase 3 §7.1 — normative for every
 * file under database/migrations/): Laravel's migrator records a migration
 * by file basename only and never diffs content, so a moved migration file
 * keeps its exact original basename — every host that already ran it sees
 * "already recorded, skip" and never re-executes it, while fresh installs
 * execute the current content. Consequently this directory is APPEND-ONLY
 * AFTER FIRST PUBLIC RELEASE: a filename, once shipped, must never be
 * reused for different content post-release — pre-release (now), content
 * may still be corrected under an existing filename precisely because
 * nothing public has run it yet outside this repository. Also (§7.1, L-2):
 * a basename duplicated between a host's own database/migrations and this
 * directory is silently last-wins in the migrator's keyBy(basename) merge —
 * never leave a copy of a moved migration behind in a host
 * (tests/Feature/MigrationSplitTest.php's basename-collision check guards
 * this).
 *
 * HOST REQUIREMENTS: this package
 * boots cleanly with no host wiring at all, but several pieces of code it
 * ships only behave correctly — rather than merely not-erroring — once a
 * host supplies the following. None of these is enforced by an exception
 * at boot time (deliberately: a host may need none of them and must
 * still boot); each is instead a silent-degradation risk if a host adds a
 * new integration point (a new SMTP/API call site, a new package route)
 * without also covering the gap below it depends on. This section is the
 * single normative list.
 *
 * 1. **The `inbox-api`/`inbox-api-wait` rate limiter names.** `routes/
 *    api.php`'s token-authenticated routes are declared behind
 *    `throttle:inbox-api`/`throttle:inbox-api-wait` middleware, but
 *    `RateLimiter::for()` *registration* has no package-level loading
 *    mechanism — it's a per-host `boot()` call the host's own top-level
 *    service provider must make (Cloud registers both in its own top-level
 *    service provider's `boot()`); the package's own Testbench suite plays
 *    the "host" role for itself via `Sendtrap\Core\Tests\PackageTestCase::
 *    registerApiRateLimiters()`, which both names must be kept in sync
 *    with). Missing either name 500s every request through it with a
 *    `MissingRateLimiterException` — not a silent degradation, but listed
 *    here since it's easy to miss which two exact names are load-bearing.
 * 2. **The `dashboard` route name.** `resources/js/Components/MessageReader/
 *    MessageReader.vue` (:207) calls `route('dashboard')` (a "back" link)
 *    — this package's own `routes/web.php` never registers that name
 *    (§1.6's H-5 decision keeps the whole `/dashboard`, `/projects/*`,
 *    `/inboxes/*` domain group host-side for all of Phase 3), so any host
 *    rendering this component must register a route literally named
 *    `dashboard` somewhere in its own routing.
 * 3. **Filesystem disks.** `Sendtrap\Core\Support\MessageStorage::disk()`
 *    reads `config('filesystems.default')` — the host's own
 *    `config/filesystems.php` must define that disk (`local` in
 *    development, commonly `s3` in production via
 *    `Sendtrap\Core\Console\Commands\MigrateStorageToS3`, which also
 *    requires disks literally named `local` and `s3` to both exist
 *    simultaneously during a migration run).
 * 4. **`config('services.spamcheck.*')`.** `Sendtrap\Core\Support\
 *    SpamCheck` reads `services.spamcheck.enabled`/`.timeout`/`.url`/
 *    `.threshold` (each with a sensible default via the `config(..., $default)`
 *    second argument) — a host that wants real spam scoring rather than
 *    the disabled-by-default no-op must publish these under its own
 *    `config/services.php`, keyed `spamcheck`.
 */
class SendtrapCoreServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sendtrap.php', 'sendtrap');

        // Plan 06 Phase 3b slice 3 (§3.2): the one contract the package
        // ships a real default for — its whole purpose is being safely
        // no-op-able. bindIf so a host binding (registered in the host's own
        // provider) wins when present; Community never overrides it. The
        // other core contracts (WorkspaceContext/WorkspaceAccess/
        // Entitlements/UsageMeter) intentionally keep NO package default.
        $this->app->bindIf(LegacyOwnershipFallback::class, NullLegacyOwnershipFallback::class);
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/sendtrap.php' => config_path('sendtrap.php'),
        ], 'sendtrap-config');

        // Plan 06 Phase 3b slice 7 (§1.6/§1.6.2): the token-authenticated
        // v1 + sandboxes route groups, moved unedited from the host's
        // routes/api.php. bootstrap/app.php's withRouting(api: ...) call
        // wraps the HOST's own routes/api.php in Route::middleware('api')
        // ->prefix('api') automatically (Illuminate\Foundation\Configuration
        // \ApplicationBuilder::buildRoutingCallback()) — that wrapping never
        // reaches a second file loaded independently via loadRoutesFrom(),
        // so it's reproduced explicitly here, byte-identical to what the
        // framework did implicitly before the move. This is what makes the
        // moved route definitions' own ->withoutMiddleware('throttle:api')
        // calls meaningful again, and keeps the final URLs at /api/v1/...
        // and /api/sandboxes/... unchanged.
        Route::middleware('api')->prefix('api')->group(function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });

        // Plan 06 Phase 3b slice 8 (§1.6/§1.6.2): the public, no-auth share
        // routes (ShareController + InboxShareController), moved unedited
        // from the host's routes/web.php:51-63. Same reasoning as the api
        // group directly above — bootstrap/app.php's withRouting(web: ...)
        // only wraps the HOST's own routes/web.php in the 'web' middleware
        // group implicitly, so it's reproduced explicitly here. The H-5
        // domain group (/dashboard, /projects/*, /inboxes/*, /messages/*)
        // stays in the host's own routes/web.php for all of Phase 3 — see
        // §1.6's H-5 decision — so it is NOT loaded from here.
        Route::middleware('web')->group(function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });

        // H-3(a) stage 2 (§1.6): explicit policy map, relocated here from
        // the host's CloudServiceProvider::boot() now that the policy
        // classes themselves moved to Sendtrap\Core\Policies\* (slice 8) —
        // a package can register its own policies against its own models
        // with no inward host-namespace reference at all. Gate's naming-
        // convention auto-discovery guesses a policy namespace from the
        // model's own namespace and can never find Sendtrap\Core\Policies\*
        // on its own (it would look for Sendtrap\Core\Models\Policies\*), so
        // this stays explicit rather than relying on discovery.
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Inbox::class, InboxPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);

        // The inbox.{inbox} broadcast channel, moved unedited from the
        // host's routes/channels.php (§1.6.2). Channels are direct
        // Broadcast::channel() registrations, not Router-loaded route
        // definitions, so this is a plain require — not loadRoutesFrom(),
        // which would silently skip the file whenever routes are cached
        // (a route:cache concern that has no bearing on broadcast channels).
        require __DIR__.'/../routes/channels.php';

        // Plan 06 Phase 3b slice 5 (§1.3): package commands register here,
        // guarded by runningInConsole() — Laravel auto-discovers the package
        // provider from composer.json extra.laravel.providers, so no host
        // wiring (bootstrap/app.php) is needed. The mail:smtp-server signature
        // is unchanged, so supervisor/deploy references keep working verbatim.
        // Slice 6 adds PruneMessages/MigrateStorageToS3/SyncCaniemailData —
        // their command signatures (mail:prune, storage:migrate-to-s3,
        // htmlcheck:sync-data) are unchanged too, so bootstrap/app.php's
        // `mail:prune` daily schedule entry (string-keyed) keeps working
        // verbatim with no edit needed there.
        if ($this->app->runningInConsole()) {
            $this->commands([
                SmtpServer::class,
                PruneMessages::class,
                MigrateStorageToS3::class,
                SyncCaniemailData::class,
            ]);
        }
    }
}

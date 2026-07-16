<?php

namespace Sendtrap\Core\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards the core/host boundary: nothing under
 * the package's {src,routes,database,config} trees may depend on
 * Cloud-only or private concepts (App\* Cloud host code, Cashier,
 * Jetstream, Socialite, Stripe, or the Cloud-only billing config) — and
 * the package's own composer.json must never declare a dependency on any
 * of those either.
 *
 * Deliberately a plain PHPUnit\Framework\TestCase (no Laravel app boot,
 * no database) — this is a static source scan, not a feature test, and it
 * must run standalone against the package tree.
 *
 * Plan 06 Phase 3b slice 9 (§6 slice 9 row, §8): moved from the host's
 * former Tests\Architecture\CoreDependencyTest (it scans package source,
 * not host source, so it belongs in the package's own suite) and hardened
 * per the Phase 1 L-1 deferred items:
 *   - case-insensitive substring matching (a `use app\Foo` or
 *     `config('BILLING...` typo-cased reference previously slipped past a
 *     case-sensitive str_contains());
 *   - a regex for the `config('billing`/`config("billing` needle so
 *     whitespace variants (`config( 'billing`, `config ('billing`) are
 *     caught too, not just the exact zero-whitespace substring;
 *   - a new `composer.json` `require`/`require-dev` key scan for
 *     `laravel/cashier`, `laravel/jetstream`, `laravel/socialite`, and any
 *     `stripe/*` package — the dependency could otherwise be declared and
 *     installed without a single `use` statement in `src/` ever naming it
 *     (e.g. relied on only via a service-provider auto-discovery side
 *     effect), which the source-text scan alone can't see.
 *
 * Plan 06 Phase 3 gate finding M-2: the scan's own root list was `src/`
 * and `composer.json` only, but by slice 9 the package also owns
 * `routes/` (api.php/web.php/channels.php — plain PHP, just as scannable
 * as anything under `src/`), `database/` (migrations + factories), and
 * `config/sendtrap.php` — none of which this scan ever swept, so a
 * Cloud-only reference landing in, say, a factory or a route closure would
 * have gone entirely undetected. `SCAN_ROOTS` below now covers all four;
 * `composer.json` keeps its own separate scan (`test_core_package_
 * composer_json_declares_no_forbidden_dependencies`) since it isn't a
 * source-text sweep. Verified red-then-green at the time of this fix: a
 * throwaway `database/factories/ZZDependencyProbeFactory.php` seeded with
 * a literal `App\Models\Team` reference failed this test before the root
 * list was extended (proving the pre-fix scan really did miss `database/`)
 * and again immediately after extending it (proving the fix catches it),
 * then was reverted — see this finding's own commit message for the full
 * transcript.
 *
 * A thin host-side pointer test remains at
 * tests/Architecture/CoreDependencyPointerTest.php (host suite) — it does
 * not re-implement this scan; it exists so a host-only `vendor/bin/phpunit`
 * run still gets a fast, direct signal if the package's own tree or
 * composer.json regresses, without duplicating this file's logic.
 */
class CoreDependencyTest extends TestCase
{
    /**
     * Package directories (relative to the package root) swept for
     * FORBIDDEN_SUBSTRINGS/FORBIDDEN_PATTERNS (M-2). Every one of these is
     * plain PHP the package itself ships and a host loads/executes
     * directly — none of them get a pass just because they aren't `src/`.
     */
    private const SCAN_ROOTS = [
        'src',
        'routes',
        'database',
        'config',
    ];

    /**
     * Substrings that must never appear anywhere under the core package's
     * scanned tree, matched case-insensitively. Extend this list in Phase 3
     * as core grows and more Cloud-only concepts are named.
     */
    private const FORBIDDEN_SUBSTRINGS = [
        'App\\',
        'Laravel\\Cashier',
        'Laravel\\Jetstream',
        'Laravel\\Socialite',
        'Stripe\\',
    ];

    /**
     * Regex needles, checked in addition to FORBIDDEN_SUBSTRINGS — case-
     * insensitive and whitespace-tolerant between `config` and the opening
     * quote, unlike a literal substring match.
     */
    private const FORBIDDEN_PATTERNS = [
        '/config\s*\(\s*[\'"]billing/i',
    ];

    /**
     * composer.json `require`/`require-dev` package-name substrings that
     * must never appear as a dependency of the package itself — the Phase 1
     * L-1 deferred item's third piece (§8): a Cloud-only package could be
     * declared as a dependency and installed without a single `use`
     * statement anywhere under src/ naming it.
     */
    private const FORBIDDEN_COMPOSER_DEPENDENCIES = [
        'cashier',
        'jetstream',
        'socialite',
        'stripe',
    ];

    public function test_core_package_src_has_no_forbidden_dependencies(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        $violations = [];

        foreach (self::SCAN_ROOTS as $relativeRoot) {
            $root = $packageRoot.'/'.$relativeRoot;

            if (! is_dir($root)) {
                // config/ in particular is a single file today, not a
                // directory guarantee for every future package layout —
                // skip a root that doesn't exist rather than failing the
                // whole scan over an optional directory.
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                foreach (self::FORBIDDEN_SUBSTRINGS as $needle) {
                    if (stripos($contents, $needle) !== false) {
                        $violations[] = sprintf('%s references forbidden "%s"', $file->getPathname(), $needle);
                    }
                }

                foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                    if (preg_match($pattern, $contents) === 1) {
                        $violations[] = sprintf('%s matches forbidden pattern "%s"', $file->getPathname(), $pattern);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Forbidden Cloud/private dependencies found in sendtrap/core:\n".implode("\n", $violations)
        );
    }

    public function test_core_package_composer_json_declares_no_forbidden_dependencies(): void
    {
        $composerJsonPath = dirname(__DIR__, 2).'/composer.json';

        $this->assertFileExists($composerJsonPath, 'sendtrap/core package composer.json is missing.');

        $composer = json_decode(file_get_contents($composerJsonPath), true, flags: JSON_THROW_ON_ERROR);

        $declaredPackages = array_merge(
            array_keys($composer['require'] ?? []),
            array_keys($composer['require-dev'] ?? [])
        );

        $violations = [];

        foreach ($declaredPackages as $package) {
            foreach (self::FORBIDDEN_COMPOSER_DEPENDENCIES as $forbidden) {
                if (stripos($package, $forbidden) !== false) {
                    $violations[] = sprintf(
                        'composer.json declares forbidden dependency "%s" (matches "%s")',
                        $package,
                        $forbidden
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Forbidden Cloud/private composer dependencies found in sendtrap/core:\n".implode("\n", $violations)
        );
    }
}

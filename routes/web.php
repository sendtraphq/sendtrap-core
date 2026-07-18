<?php

use Illuminate\Support\Facades\Route;
use Sendtrap\Core\Http\Controllers\InboxShareController;
use Sendtrap\Core\Http\Controllers\ShareController;
use Sendtrap\Core\Http\Middleware\DenySearchIndexing;

/*
 * Plan 06 Phase 3b slice 8 (§1.6/§1.6.2): moved unedited from the host's
 * routes/web.php:51-63 — only the controller imports above changed
 * namespace. URLs and route names are unchanged (share.show, share.html,
 * share.inbox.*). The host's `web:` routing entry gets the 'web' middleware
 * group applied implicitly by bootstrap/app.php's withRouting(web: ...) —
 * that wrapping never reaches a second file loaded independently via
 * loadRoutesFrom(), so SendtrapCoreServiceProvider::boot() reproduces it
 * explicitly (same pattern as routes/api.php's 'api' group wrap, §1.6.2).
 * This is a no-auth, public surface — no session/CSRF-sensitive verbs here
 * (every route is GET) — but the 'web' group is still required for Inertia
 * (Share/Show, Share/InboxShow) and the globally-appended
 * HandleInertiaRequests middleware to run. CSP headers for the rendered
 * HTML/iframe responses are set directly in the controllers themselves
 * (ShareController::html(), InboxShareController::html()/attachment()), not
 * via route-level middleware, so they moved unedited with the controllers.
 *
 * H-5 cascade: the domain group (`/dashboard`, `/projects/*`, `/inboxes/*`,
 * `/messages/*`) does NOT move here — it stays in the host's own
 * routes/web.php per §1.6's H-5 decision (InboxController/ProjectController
 * stay host-side for all of Phase 3).
 */

/*
 * Public share links — no auth.
 */
Route::get('/share/{token}', [ShareController::class, 'show'])->name('share.show')->middleware(DenySearchIndexing::class);
Route::get('/share/{token}/html', [ShareController::class, 'html'])->name('share.html')->middleware(DenySearchIndexing::class);

/*
 * Public inbox share links — no auth. Lets a whole inbox be shared with an
 * external client (e.g. to watch test emails land on a dev site).
 */
Route::get('/share/inbox/{token}', [InboxShareController::class, 'show'])->name('share.inbox.show')->middleware(DenySearchIndexing::class);
Route::get('/share/inbox/{token}/messages', [InboxShareController::class, 'messages'])->name('share.inbox.messages')->middleware(DenySearchIndexing::class);
Route::get('/share/inbox/{token}/messages/{message}', [InboxShareController::class, 'message'])->name('share.inbox.message')->middleware(DenySearchIndexing::class);
Route::get('/share/inbox/{token}/messages/{message}/html', [InboxShareController::class, 'html'])->name('share.inbox.html')->middleware(DenySearchIndexing::class);
Route::get('/share/inbox/{token}/messages/{message}/attachments/{attachment}', [InboxShareController::class, 'attachment'])
    ->name('share.inbox.attachment')->middleware(DenySearchIndexing::class);

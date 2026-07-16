<?php

namespace Sendtrap\Core\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Plan 06 Phase 3b slice 7 (§1.6): base controller for the package's own
 * HTTP controllers (currently just Api\*, the token-authenticated surface —
 * ShareController/InboxShareController/the web MessageController move in
 * slice 8 onto this same base). This is the vanilla Laravel skeleton shape
 * (AuthorizesRequests only) — the host's own base controller class
 * additionally carries an ipRule() validation helper used only by the
 * web-side InboxController/ProjectController/TeamAccessController, none of
 * which move in Phase 3 (H-5) or are exercised by any package controller, so
 * that helper stays host-side rather than being duplicated here.
 */
abstract class Controller
{
    use AuthorizesRequests;
}

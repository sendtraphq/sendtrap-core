<?php

use Illuminate\Support\Facades\Route;
use Sendtrap\Core\Http\Controllers\Api\ExpectController;
use Sendtrap\Core\Http\Controllers\Api\ExtractController;
use Sendtrap\Core\Http\Controllers\Api\InboxController;
use Sendtrap\Core\Http\Controllers\Api\MailtrapCompatController;
use Sendtrap\Core\Http\Controllers\Api\MessageController;
use Sendtrap\Core\Http\Middleware\AuthenticateInboxToken;

/*
 * Plan 06 Phase 3b slice 7 (§1.6/§1.6.2): moved unedited from the host's
 * routes/api.php:14-58 — only the imports above changed namespace. The host
 * routes/api.php previously got the /api prefix + 'api' middleware group
 * (with throttle:api) implicitly, via bootstrap/app.php's
 * withRouting(api: ...) wrapping. Loaded here through
 * SendtrapCoreServiceProvider::boot() instead, which is not passed through
 * that same wrapping, so the provider applies the identical
 * Route::middleware('api')->prefix('api') wrap itself before requiring this
 * file — see the provider for that group. Route *names* (api.messages.raw,
 * etc.) are unchanged.
 */

/*
 * Authenticated by a per-inbox API token
 * (Authorization: Bearer <api_token>).
 */
Route::middleware([AuthenticateInboxToken::class, 'throttle:inbox-api'])->withoutMiddleware('throttle:api')->prefix('v1')->group(function () {
    Route::get('/inbox', [InboxController::class, 'show']);

    Route::get('/messages', [MessageController::class, 'index']);
    Route::post('/assert', [MessageController::class, 'assert'])->middleware('throttle:inbox-api-wait');
    Route::post('/expect', ExpectController::class)->middleware('throttle:inbox-api-wait');
    Route::delete('/messages', [MessageController::class, 'destroyAll']);
    Route::get('/messages/{message}', [MessageController::class, 'show']);
    Route::post('/messages/{message}/extract', ExtractController::class)->name('api.messages.extract');
    Route::get('/messages/{message}/raw', [MessageController::class, 'raw'])->name('api.messages.raw');
    Route::get('/messages/{message}/html', [MessageController::class, 'html'])->name('api.messages.html');
    Route::get('/messages/{message}/compatibility', [MessageController::class, 'compatibility'])->name('api.messages.compatibility');
    Route::get('/messages/{message}/attachments/{attachment}', [MessageController::class, 'attachment'])->name('api.messages.attachment');
    Route::patch('/messages/{message}', [MessageController::class, 'update']);
    Route::delete('/messages/{message}', [MessageController::class, 'destroy']);
});

/*
 * Mailtrap-compatible aliases — same token auth, {sandbox} is accepted but
 * not validated since the token already scopes the request to one inbox.
 * Lets an existing Mailtrap test helper work with just a base URL + token
 * swap. See Sendtrap\Core\Http\Controllers\Api\MailtrapCompatController.
 */
Route::middleware([AuthenticateInboxToken::class, 'throttle:inbox-api'])
    ->withoutMiddleware('throttle:api')
    ->prefix('sandboxes/{sandbox}')
    ->group(function () {
        Route::get('/messages', [MailtrapCompatController::class, 'index']);
        Route::get('/messages/{message}', [MailtrapCompatController::class, 'show']);
        Route::patch('/messages/{message}', [MailtrapCompatController::class, 'update']);
        Route::delete('/messages/{message}', [MailtrapCompatController::class, 'destroy']);
        Route::get('/messages/{message}/body.txt', [MailtrapCompatController::class, 'bodyTxt']);
        Route::get('/messages/{message}/body.html', [MailtrapCompatController::class, 'bodyHtml']);
        Route::get('/messages/{message}/body.htmlsource', [MailtrapCompatController::class, 'bodyHtmlSource']);
        Route::get('/messages/{message}/body.raw', [MailtrapCompatController::class, 'bodyRaw']);
        Route::get('/messages/{message}/body.eml', [MailtrapCompatController::class, 'bodyEml']);
        Route::get('/messages/{message}/mail_headers', [MailtrapCompatController::class, 'mailHeaders']);
        Route::get('/messages/{message}/attachments', [MailtrapCompatController::class, 'attachments']);
        Route::get('/messages/{message}/attachments/{attachment}', [MailtrapCompatController::class, 'attachment']);
        Route::get('/messages/{message}/attachments/{attachment}/download', [MailtrapCompatController::class, 'attachmentDownload']);
        Route::patch('/clean', [MailtrapCompatController::class, 'clean']);
        Route::patch('/all_read', [MailtrapCompatController::class, 'allRead']);
    });

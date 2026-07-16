<?php

namespace Sendtrap\Core\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;

trait ScopedToInboxToken
{
    protected function inbox(Request $request): Inbox
    {
        return $request->attributes->get('inbox');
    }

    protected function ensureOwned(Request $request, Message $message): void
    {
        abort_if($message->inbox_id !== $this->inbox($request)->id, 404);
    }
}

<?php

namespace Sendtrap\Core\Http\Controllers\Api;

use Illuminate\Http\Request;
use Sendtrap\Core\Http\Controllers\Controller;
use Sendtrap\Core\Http\Resources\InboxResource;
use Sendtrap\Core\Models\Inbox;

class InboxController extends Controller
{
    /**
     * Details about the authenticated inbox.
     */
    public function show(Request $request)
    {
        /** @var Inbox $inbox */
        $inbox = $request->attributes->get('inbox');

        return new InboxResource($inbox->loadCount('messages'));
    }
}

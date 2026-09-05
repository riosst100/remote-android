<?php

namespace App\Http\Controllers\Broadcasting;

use App\Http\Controllers\Controller;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Http\Request;

/**
 * Two broadcasting auth endpoints exist because admins and devices use
 * different guards (session/Sanctum "web" vs. bearer-token "device").
 * Each endpoint authenticates its own audience before delegating to
 * Laravel's stock channel-authorization logic in routes/channels.php.
 */
class BroadcastAuthController extends Controller
{
    public function admin(Request $request, BroadcastController $controller)
    {
        return $controller->authenticate($request);
    }

    public function device(Request $request, BroadcastController $controller)
    {
        $request->setUserResolver(fn () => auth('device')->user());

        return $controller->authenticate($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Role
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();
        abort_unless($user && (in_array($user->role, $roles, true)
            || ($user->isSuperAdmin() && in_array('admin', $roles, true))), 403);

        return $next($request);
    }
}

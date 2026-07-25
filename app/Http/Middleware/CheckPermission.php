<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $permission
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = Auth::user();
        
        // If user is not authenticated, return unauthorized
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // If user is admin, bypass permission check
        if ($user->hasRole('admin')) {
            return $next($request);
        }

        // Check if user has the required permission
        if (!$user->hasPermissionTo($permission)) {
            return response()->json(['message' => 'Unauthorized. Missing permission: ' . $permission], 403);
        }

        return $next($request);
    }
}

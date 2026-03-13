<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class ForcePasswordChangeMiddleware
{
    /**
     * Block access for users that still must change password,
     * except allowed auth endpoints.
     */
    public function handle(Request $request, Closure $next)
    {
        $authUserId = $request->auth_user_id;
        if (!$authUserId) {
            return $next($request);
        }

        if (
            $request->is('api/auth/change-password')
            || $request->is('auth/change-password')
            || $request->is('api/auth/logout')
            || $request->is('auth/logout')
        ) {
            return $next($request);
        }

        $user = User::with('loginCheck')->find($authUserId);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        if ((bool) ($user->loginCheck->IsChangePassword ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Password change required before accessing this resource',
                'data' => [
                    'Status' => 'password_change_required',
                    'RequiresPasswordChange' => true,
                ],
            ], 403);
        }

        return $next($request);
    }
}

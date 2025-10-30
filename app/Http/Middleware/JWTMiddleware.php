<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Helpers\JWTHelper;
use App\Models\User;

class JWTMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token not provided'
            ], 401);
        }

        try {
            $decoded = JWTHelper::verifyToken($token);

            // Check if token type is access token
            if ($decoded['type'] !== 'access') {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid token type. Use access token.'
                ], 401);
            }

            // Get user
            $user = User::find($decoded['sub']);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Check if user is active
            if (!$user->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User account is not active'
                ], 403);
            }

            // Add user to request
            $request->merge(['auth_user' => $user]);
            $request->merge(['auth_user_id' => $user->UserID]);

            // Check if token needs refresh (3/4 of lifetime)
            if (JWTHelper::needsRefresh($token)) {
                $request->merge(['token_needs_refresh' => true]);
            }

            return $next($request);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired token',
                'error' => $e->getMessage()
            ], 401);
        }
    }
}
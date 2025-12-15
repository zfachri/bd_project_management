<?php

/**
 * Check Permission Middleware
 * File: app/Http/Middleware/CheckPermission.php
 * 
 * Usage in routes:
 * Route::get('/data', [Controller::class, 'method'])->middleware('permission:ModuleName.action');
 * 
 * Examples:
 * ->middleware('permission:JobDescription.view')
 * ->middleware('permission:Project.create')
 * ->middleware('permission:Document.edit')
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\PermissionService;

class CheckPermission
{
    protected $permissionService;

    public function __construct(PermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $permission  Format: "ModuleName.action"
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $permission)
    {
        // Get authenticated employee ID from JWT middleware
        $employeeId = $request->auth_user_id;

        if (!$employeeId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - User not authenticated'
            ], 401);
        }

        // Parse permission string (e.g., "JobDescription.view" -> ["JobDescription", "view"])
        $parts = explode('.', $permission);

        if (count($parts) !== 2) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid permission format. Use: ModuleName.action'
            ], 500);
        }

        [$moduleName, $action] = $parts;

        // Check permission
        if (!$this->permissionService->hasPermission($employeeId, $moduleName, $action)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden - You do not have permission to perform this action',
                'required_permission' => $permission
            ], 403);
        }

        return $next($request);
    }
}
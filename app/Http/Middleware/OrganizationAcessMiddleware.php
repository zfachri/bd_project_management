<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Organization;

class OrganizationAccessMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->auth_user;

        // Admin bisa akses semua
        if ($user->IsAdministrator) {
            return $next($request);
        }

        // Get organization ID dari request (bisa dari route parameter atau query)
        $requestedOrgId = $request->route('id') ?? $request->input('organization_id');

        // Jika tidak ada organization yang diminta, skip check
        if (!$requestedOrgId) {
            return $next($request);
        }

        // Check access
        if (!$this->hasAccessToOrganization($user, $requestedOrgId)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this organization'
            ], 403);
        }

        return $next($request);
    }

    /**
     * Check if user has access to organization
     */
    private function hasAccessToOrganization($user, $requestedOrgId)
    {
        $userOrgId = $user->OrganizationID;

        // User bisa akses organization sendiri
        if ($userOrgId == $requestedOrgId) {
            return true;
        }

        // Get user's organization
        $userOrg = Organization::find($userOrgId);
        $requestedOrg = Organization::find($requestedOrgId);

        if (!$userOrg || !$requestedOrg) {
            return false;
        }

        // Check if requested org is descendant of user's org
        return $this->isDescendant($requestedOrgId, $userOrgId);
    }

    /**
     * Check if organization is descendant of parent
     */
    private function isDescendant($orgId, $ancestorId)
    {
        $org = Organization::find($orgId);
        
        if (!$org) {
            return false;
        }

        // Traverse up the tree
        $currentOrg = $org;
        $maxDepth = 10; // Prevent infinite loop
        $depth = 0;

        while ($currentOrg && $depth < $maxDepth) {
            // Jika parent adalah dirinya sendiri (root), stop
            if ($currentOrg->ParentOrganizationID == $currentOrg->OrganizationID) {
                break;
            }

            // Jika parent sama dengan ancestor yang dicari
            if ($currentOrg->ParentOrganizationID == $ancestorId) {
                return true;
            }

            // Naik ke parent
            $currentOrg = Organization::find($currentOrg->ParentOrganizationID);
            $depth++;
        }

        return false;
    }
}
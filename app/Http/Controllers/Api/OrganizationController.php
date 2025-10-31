<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class OrganizationController extends Controller
{
    /**
     * Get all organizations (with hierarchy)
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $search = $request->input('search');
        $withHierarchy = $request->input('with_hierarchy', false);

        $query = Organization::active();

        if ($search) {
            $query->where('OrganizationName', 'like', "%{$search}%");
        }

        if ($withHierarchy) {
            // Get only root organizations with their children
            $organizations = Organization::active()
                ->whereNull('ParentOrganizationID')
                ->with('children')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Organizations retrieved successfully',
                'data' => $organizations
            ], 200);
        }

        $organizations = $query->with('parent')->paginate($perPage);

        $organizations->getCollection()->transform(function($org) {
            return [
                'OrganizationID' => $org->OrganizationID,
                'ParentOrganizationID' => $org->ParentOrganizationID,
                'ParentOrganizationName' => $org->parent->OrganizationName ?? null,
                'LevelNo' => $org->LevelNo,
                'IsChild' => $org->IsChild,
                'OrganizationName' => $org->OrganizationName,
                'IsActive' => $org->IsActive,
                'CreatedAt' => $org->AtTimeStamp,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Organizations retrieved successfully',
            'data' => $organizations
        ], 200);
    }

    /**
     * Get all organizations without pagination
     */
    public function all(Request $request)
    {
        $withHierarchy = $request->input('with_hierarchy', false);

        if ($withHierarchy) {
            $organizations = Organization::active()
                ->whereNull('ParentOrganizationID')
                ->with('children')
                ->get();
        } else {
            $organizations = Organization::active()->get();
        }

        return response()->json([
            'success' => true,
            'message' => 'Organizations retrieved successfully',
            'data' => $organizations
        ], 200);
    }

    /**
     * Get single organization
     */
    public function show($id)
    {
        $organization = Organization::with(['parent', 'children'])->find($id);

        if (!$organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Organization retrieved successfully',
            'data' => [
                'OrganizationID' => $organization->OrganizationID,
                'ParentOrganizationID' => $organization->ParentOrganizationID,
                'ParentOrganizationName' => $organization->parent->OrganizationName ?? null,
                'LevelNo' => $organization->LevelNo,
                'IsChild' => $organization->IsChild,
                'OrganizationName' => $organization->OrganizationName,
                'IsActive' => $organization->IsActive,
                'IsDelete' => $organization->IsDelete,
                'Children' => $organization->children,
                'CreatedAt' => $organization->AtTimeStamp,
            ]
        ], 200);
    }

    /**
     * Get organizations by level
     */
    public function getByLevel($level)
    {
        $organizations = Organization::active()
            ->where('LevelNo', $level)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Organizations retrieved successfully',
            'data' => $organizations
        ], 200);
    }

    /**
     * Get children of organization
     */
    public function getChildren($id)
    {
        $organization = Organization::find($id);

        if (!$organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found'
            ], 404);
        }

        $children = Organization::active()
            ->where('ParentOrganizationID', $id)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Children organizations retrieved successfully',
            'data' => $children
        ], 200);
    }

    /**
     * Create new organization
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ParentOrganizationID' => 'nullable|integer|exists:organization,OrganizationID',
            'OrganizationName' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            // Determine LevelNo and IsChild
            $levelNo = 1;
            $isChild = false;

            if ($request->ParentOrganizationID) {
                $parent = Organization::find($request->ParentOrganizationID);
                $levelNo = $parent->LevelNo + 1;
                $isChild = true;
            }

            $organization = Organization::create([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ParentOrganizationID' => $request->ParentOrganizationID,
                'LevelNo' => $levelNo,
                'IsChild' => $isChild,
                'OrganizationName' => $request->OrganizationName,
                'IsActive' => true,
                'IsDelete' => false,
            ]);

            // Create audit log
            AuditLog::create([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'organization',
                'ReferenceRecordID' => $organization->OrganizationID,
                'Data' => json_encode([
                    'OrganizationName' => $organization->OrganizationName,
                    'ParentOrganizationID' => $organization->ParentOrganizationID,
                    'LevelNo' => $organization->LevelNo,
                ]),
                'Note' => 'Organization created'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Organization created successfully',
                'data' => $organization
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create organization',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update organization
     */
    public function update(Request $request, $id)
    {
        $organization = Organization::find($id);

        if (!$organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'OrganizationName' => 'required|string|max:100',
            'IsActive' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            $oldData = [
                'OrganizationName' => $organization->OrganizationName,
                'IsActive' => $organization->IsActive,
            ];

            $updateData = [
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'OrganizationName' => $request->OrganizationName,
            ];

            if ($request->has('IsActive')) {
                $updateData['IsActive'] = $request->IsActive;
            }

            $organization->update($updateData);

            // Create audit log
            AuditLog::create([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'organization',
                'ReferenceRecordID' => $organization->OrganizationID,
                'Data' => json_encode([
                    'Old' => $oldData,
                    'New' => [
                        'OrganizationName' => $organization->OrganizationName,
                        'IsActive' => $organization->IsActive,
                    ]
                ]),
                'Note' => 'Organization updated'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Organization updated successfully',
                'data' => $organization
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update organization',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Soft delete organization
     */
    public function destroy(Request $request, $id)
    {
        $organization = Organization::find($id);

        if (!$organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found'
            ], 404);
        }

        // Check if organization has children
        $hasChildren = Organization::where('ParentOrganizationID', $id)
            ->where('IsDelete', false)
            ->exists();

        if ($hasChildren) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete organization with active children'
            ], 400);
        }

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            $organization->update([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'IsDelete' => true,
                'IsActive' => false,
            ]);

            // Create audit log
            AuditLog::create([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
                'ReferenceTable' => 'organization',
                'ReferenceRecordID' => $organization->OrganizationID,
                'Data' => json_encode([
                    'OrganizationName' => $organization->OrganizationName,
                ]),
                'Note' => 'Organization deleted (soft delete)'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Organization deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete organization',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle active status
     */
    public function toggleActive(Request $request, $id)
    {
        $organization = Organization::find($id);

        if (!$organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found'
            ], 404);
        }

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            $newStatus = !$organization->IsActive;

            $organization->update([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'IsActive' => $newStatus,
            ]);

            $action = $newStatus ? 'activated' : 'deactivated';

            // Create audit log
            AuditLog::create([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'organization',
                'ReferenceRecordID' => $organization->OrganizationID,
                'Data' => json_encode([
                    'Action' => $action,
                    'IsActive' => $newStatus,
                ]),
                'Note' => "Organization {$action}"
            ]);

            return response()->json([
                'success' => true,
                'message' => "Organization {$action} successfully",
                'data' => [
                    'OrganizationID' => $organization->OrganizationID,
                    'OrganizationName' => $organization->OrganizationName,
                    'IsActive' => $organization->IsActive,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle organization status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
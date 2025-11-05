<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\JobDescription;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PositionController extends Controller
{
    /**
     * Get all positions (with hierarchy)
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $search = $request->input('search');
        $organizationId = $request->input('organization_id');
        $withHierarchy = $request->input('with_hierarchy', false);

        $query = Position::active()->with(['organization', 'positionLevel']);

        if ($search) {
            $query->where('PositionName', 'like', "%{$search}%");
        }

        if ($organizationId) {
            $query->where('OrganizationID', $organizationId);
        }

        if ($withHierarchy) {
            // Get only root positions with their children
            $positions = Position::active()
                ->whereNull('ParentPositionID')
                ->with(['children', 'organization', 'positionLevel'])
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Positions retrieved successfully',
                'data' => $positions
            ], 200);
        }

        $positions = $query->with('parent')->paginate($perPage);

        $positions->getCollection()->transform(function($position) {
            return [
                'PositionID' => $position->PositionID,
                'OrganizationID' => $position->OrganizationID,
                'OrganizationName' => $position->organization->OrganizationName ?? null,
                'ParentPositionID' => $position->ParentPositionID,
                'ParentPositionName' => $position->parent->PositionName ?? null,
                'LevelNo' => $position->LevelNo,
                'IsChild' => $position->IsChild,
                'PositionName' => $position->PositionName,
                'PositionLevelID' => $position->PositionLevelID,
                'PositionLevelName' => $position->positionLevel->PositionLevelName ?? null,
                'RequirementQuantity' => $position->RequirementQuantity,
                'IsActive' => $position->IsActive,
                'CreatedAt' => $position->AtTimeStamp,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Positions retrieved successfully',
            'data' => $positions
        ], 200);
    }

    /**
     * Get all positions without pagination
     */
    public function all(Request $request)
    {
        $organizationId = $request->input('organization_id');
        $withHierarchy = $request->input('with_hierarchy', false);

        $query = Position::active();

        if ($organizationId) {
            $query->where('OrganizationID', $organizationId);
        }

        if ($withHierarchy) {
            $positions = $query->whereNull('ParentPositionID')
                ->with(['children', 'organization', 'positionLevel'])
                ->get();
        } else {
            $positions = $query->with(['organization', 'positionLevel'])->get();
        }

        return response()->json([
            'success' => true,
            'message' => 'Positions retrieved successfully',
            'data' => $positions
        ], 200);
    }

    /**
     * Get single position with job description
     */
    public function show($id)
    {
        $position = Position::with([
            'organization', 
            'parent', 
            'children', 
            'positionLevel',
            'jobDescriptions' => function($query) {
                $query->active();
            }
        ])->find($id);

        if (!$position) {
            return response()->json([
                'success' => false,
                'message' => 'Position not found'
            ], 404);
        }

        // Get the latest job description
        $jobDescription = $position->jobDescriptions->first();

        return response()->json([
            'success' => true,
            'message' => 'Position retrieved successfully',
            'data' => [
                'PositionID' => $position->PositionID,
                'OrganizationID' => $position->OrganizationID,
                'OrganizationName' => $position->organization->OrganizationName ?? null,
                'ParentPositionID' => $position->ParentPositionID,
                'ParentPositionName' => $position->parent->PositionName ?? null,
                'LevelNo' => $position->LevelNo,
                'IsChild' => $position->IsChild,
                'PositionName' => $position->PositionName,
                'PositionLevelID' => $position->PositionLevelID,
                'PositionLevelName' => $position->positionLevel->PositionLevelName ?? null,
                'RequirementQuantity' => $position->RequirementQuantity,
                'IsActive' => $position->IsActive,
                'IsDelete' => $position->IsDelete,
                'Children' => $position->children,
                'JobDescription' => $jobDescription,
                'CreatedAt' => $position->AtTimeStamp,
            ]
        ], 200);
    }

    /**
     * Get positions by organization
     */
    public function getByOrganization($organizationId)
    {
        $positions = Position::active()
            ->where('OrganizationID', $organizationId)
            ->with(['positionLevel'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Positions retrieved successfully',
            'data' => $positions
        ], 200);
    }

    /**
     * Get children of position
     */
    public function getChildren($id)
    {
        $position = Position::find($id);

        if (!$position) {
            return response()->json([
                'success' => false,
                'message' => 'Position not found'
            ], 404);
        }

        $children = Position::active()
            ->where('ParentPositionID', $id)
            ->with(['positionLevel'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Children positions retrieved successfully',
            'data' => $children
        ], 200);
    }

    /**
     * Create new position with job description
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'OrganizationID' => 'required|integer|exists:organization,OrganizationID',
            'ParentPositionID' => 'nullable|integer|exists:position,PositionID',
            'PositionName' => 'required|string|max:100',
            'PositionLevelID' => 'required|integer|exists:position_level,PositionLevelID',
            'RequirementQuantity' => 'nullable|integer|min:0',
            
            // Job Description fields (all optional)
            'JobDescription' => 'nullable|string',
            'MainTaskDescription' => 'nullable|string',
            'MainTaskMeasurement' => 'nullable|string',
            'InternalRelationshipDescription' => 'nullable|string',
            'InternalRelationshipObjective' => 'nullable|string',
            'ExternalRelationshipDescription' => 'nullable|string',
            'ExternalRelationshipObjective' => 'nullable|string',
            'TechnicalCompetency' => 'nullable|string',
            'SoftCompetency' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            // Determine LevelNo and IsChild
            $levelNo = 1;
            $isChild = false;

            if ($request->ParentPositionID) {
                $parent = Position::find($request->ParentPositionID);
                $levelNo = $parent->LevelNo + 1;
                $isChild = true;
            }

            // Create position
            $position = Position::create([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'OrganizationID' => $request->OrganizationID,
                'ParentPositionID' => $request->ParentPositionID,
                'LevelNo' => $levelNo,
                'IsChild' => $isChild,
                'PositionName' => $request->PositionName,
                'PositionLevelID' => $request->PositionLevelID,
                'RequirementQuantity' => $request->RequirementQuantity ?? 0,
                'IsActive' => true,
                'IsDelete' => false,
            ]);

            // Create job description if provided
            if ($request->has('JobDescription') || 
                $request->has('MainTaskDescription') || 
                $request->has('TechnicalCompetency') ||
                $request->has('SoftCompetency')) {
                
                JobDescription::create([
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'I',
                    'OrganizationID' => $request->OrganizationID,
                    'PositionID' => $position->PositionID,
                    'JobDescription' => $request->JobDescription,
                    'MainTaskDescription' => $request->MainTaskDescription,
                    'MainTaskMeasurement' => $request->MainTaskMeasurement,
                    'InternalRelationshipDescription' => $request->InternalRelationshipDescription,
                    'InternalRelationshipObjective' => $request->InternalRelationshipObjective,
                    'ExternalRelationshipDescription' => $request->ExternalRelationshipDescription,
                    'ExternalRelationshipObjective' => $request->ExternalRelationshipObjective,
                    'TechnicalCompetency' => $request->TechnicalCompetency,
                    'SoftCompetency' => $request->SoftCompetency,
                    'IsDelete' => false,
                ]);
            }

            // Create audit log
            AuditLog::create([
                'AuditLog'=>Carbon::now()->timestamp.random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'Position',
                'ReferenceRecordID' => $position->PositionID,
                'Data' => json_encode([
                    'PositionName' => $position->PositionName,
                    'OrganizationID' => $position->OrganizationID,
                    'ParentPositionID' => $position->ParentPositionID,
                    'LevelNo' => $position->LevelNo,
                ]),
                'Note' => 'Position created with job description'
            ]);

            DB::commit();

            // Reload with relationships
            $position->load(['organization', 'positionLevel', 'jobDescriptions']);

            return response()->json([
                'success' => true,
                'message' => 'Position created successfully',
                'data' => $position
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create position',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update position with job description
     */
    public function update(Request $request, $id)
    {
        $position = Position::find($id);

        if (!$position) {
            return response()->json([
                'success' => false,
                'message' => 'Position not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'PositionName' => 'required|string|max:100',
            'PositionLevelID' => 'required|integer|exists:position_level,PositionLevelID',
            'RequirementQuantity' => 'nullable|integer|min:0',
            'IsActive' => 'nullable|boolean',
            
            // Job Description fields (all optional)
            'JobDescription' => 'nullable|string',
            'MainTaskDescription' => 'nullable|string',
            'MainTaskMeasurement' => 'nullable|string',
            'InternalRelationshipDescription' => 'nullable|string',
            'InternalRelationshipObjective' => 'nullable|string',
            'ExternalRelationshipDescription' => 'nullable|string',
            'ExternalRelationshipObjective' => 'nullable|string',
            'TechnicalCompetency' => 'nullable|string',
            'SoftCompetency' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            $oldData = [
                'PositionName' => $position->PositionName,
                'PositionLevelID' => $position->PositionLevelID,
                'RequirementQuantity' => $position->RequirementQuantity,
                'IsActive' => $position->IsActive,
            ];

            $updateData = [
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'PositionName' => $request->PositionName,
                'PositionLevelID' => $request->PositionLevelID,
            ];

            if ($request->has('RequirementQuantity')) {
                $updateData['RequirementQuantity'] = $request->RequirementQuantity;
            }

            if ($request->has('IsActive')) {
                $updateData['IsActive'] = $request->IsActive;
            }

            $position->update($updateData);

            // Update or create job description
            $jobDescription = JobDescription::where('PositionID', $id)
                ->where('IsDelete', false)
                ->first();

            $jobDescriptionData = [
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => $jobDescription ? 'U' : 'I',
                'OrganizationID' => $position->OrganizationID,
                'PositionID' => $position->PositionID,
                'JobDescription' => $request->JobDescription,
                'MainTaskDescription' => $request->MainTaskDescription,
                'MainTaskMeasurement' => $request->MainTaskMeasurement,
                'InternalRelationshipDescription' => $request->InternalRelationshipDescription,
                'InternalRelationshipObjective' => $request->InternalRelationshipObjective,
                'ExternalRelationshipDescription' => $request->ExternalRelationshipDescription,
                'ExternalRelationshipObjective' => $request->ExternalRelationshipObjective,
                'TechnicalCompetency' => $request->TechnicalCompetency,
                'SoftCompetency' => $request->SoftCompetency,
                'IsDelete' => false,
            ];

            if ($jobDescription) {
                $jobDescription->update($jobDescriptionData);
            } else {
                JobDescription::create($jobDescriptionData);
            }

            // Create audit log
            AuditLog::create([
                'AuditLog'=>Carbon::now()->timestamp.random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'Position',
                'ReferenceRecordID' => $position->PositionID,
                'Data' => json_encode([
                    'Old' => $oldData,
                    'New' => [
                        'PositionName' => $position->PositionName,
                        'PositionLevelID' => $position->PositionLevelID,
                        'RequirementQuantity' => $position->RequirementQuantity,
                        'IsActive' => $position->IsActive,
                    ]
                ]),
                'Note' => 'Position updated with job description'
            ]);

            DB::commit();

            // Reload with relationships
            $position->load(['organization', 'positionLevel', 'jobDescriptions']);

            return response()->json([
                'success' => true,
                'message' => 'Position updated successfully',
                'data' => $position
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update position',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Soft delete position
     */
    public function destroy(Request $request, $id)
    {
        $position = Position::find($id);

        if (!$position) {
            return response()->json([
                'success' => false,
                'message' => 'Position not found'
            ], 404);
        }

        // Check if position has children
        $hasChildren = Position::where('ParentPositionID', $id)
            ->where('IsDelete', false)
            ->exists();

        if ($hasChildren) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete position with active children'
            ], 400);
        }

        DB::beginTransaction();

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            // Soft delete position
            $position->update([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'IsDelete' => true,
                'IsActive' => false,
            ]);

            // Soft delete job descriptions
            JobDescription::where('PositionID', $id)
                ->update([
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'U',
                    'IsDelete' => true,
                ]);

            // Create audit log
            AuditLog::create([
                'AuditLog'=>Carbon::now()->timestamp.random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
                'ReferenceTable' => 'Position',
                'ReferenceRecordID' => $position->PositionID,
                'Data' => json_encode([
                    'PositionName' => $position->PositionName,
                ]),
                'Note' => 'Position deleted (soft delete)'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Position deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete position',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle active status
     */
    public function toggleActive(Request $request, $id)
    {
        $position = Position::find($id);

        if (!$position) {
            return response()->json([
                'success' => false,
                'message' => 'Position not found'
            ], 404);
        }

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            $newStatus = !$position->IsActive;

            $position->update([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'IsActive' => $newStatus,
            ]);

            $action = $newStatus ? 'activated' : 'deactivated';

            // Create audit log
            AuditLog::create([
                'AuditLog'=>Carbon::now()->timestamp.random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'Position',
                'ReferenceRecordID' => $position->PositionID,
                'Data' => json_encode([
                    'Action' => $action,
                    'IsActive' => $newStatus,
                ]),
                'Note' => "Position {$action}"
            ]);

            return response()->json([
                'success' => true,
                'message' => "Position {$action} successfully",
                'data' => [
                    'PositionID' => $position->PositionID,
                    'PositionName' => $position->PositionName,
                    'IsActive' => $position->IsActive,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle position status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
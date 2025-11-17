<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\JobDescription;
use App\Models\AuditLog;
use App\Models\EmployeePosition;
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
            $query = Position::active()
                ->whereColumn('ParentPositionID', 'PositionID');
                // ->with(['children', 'positionLevel'])
                // ->get();
            if($organizationId) {
                $query->where('OrganizationID', $organizationId);
            }

            $rootPositions = $query->get();
            $tree = [];
            foreach ($rootPositions as $rootPosition) {
                $tree[] = $this->buildPositionHierarchy($rootPosition, $organizationId, false);
            }

            return response()->json([
                'success' => true,
                'message' => 'Positions retrieved successfully',
                'data' => $tree
            ], 200);
        }

        $positions = $query->with('parent')->paginate($perPage);

        $positions->getCollection()->transform(function ($position) {
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
            $rootPositions = $query->whereColumn('ParentPositionID', 'PositionID')
                // ->with(['children', 'organization', 'positionLevel'])
                ->get();
            $positions = [];
            foreach ($rootPositions as $rootPosition) {
                $positions[] = $this->buildPositionHierarchy($rootPosition, $organizationId, false);
            }

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
            'jobDescriptions' => function ($query) {
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
            'OrganizationID' => 'required|integer|exists:Organization,OrganizationID',
            'ParentPositionID' => 'nullable|integer|exists:Position,PositionID',
            'PositionName' => 'required|string|max:100',
            'PositionLevelID' => 'required|integer|exists:PositionLevel,PositionLevelID',
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
                'PositionID' => Carbon::now()->timestamp . random_numbersu(5),
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

            if(!$position->ParentPositionID) {
                $position->ParentPositionID = $position->PositionID;
                $position->save();
            }

            // Create job description if provided
            if (
                $request->has('JobDescription') ||
                $request->has('MainTaskDescription') ||
                $request->has('TechnicalCompetency') ||
                $request->has('SoftCompetency')
            ) {

                JobDescription::create([
                    'RecordID' => Carbon::now()->timestamp.random_numbersu(5),
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
                'AuditLog' => Carbon::now()->timestamp . random_numbersu(5),
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
            $position->load(['Organization', 'PositionLevel', 'JobDescriptions']);

            return response()->json([
                'success' => true,
                'message' => 'Position created successfully',
                'data' => $position
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            AuditLog::create([  
                'AuditLog' => Carbon::now()->timestamp . random_numbersu(5),
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
                $updateData['IsActive'] = filter_var($request->IsActive, FILTER_VALIDATE_BOOLEAN);

                if (!$updateData['IsActive']) {
                    $existsAsParent = Position::where('ParentPositionID', $id)
                        ->where('IsActive', true)
                        ->where('IsDelete', false)
                        ->exists();

                    $exists = EmployeePosition::where('PositionID', $id)
                        ->where('IsDelete', false)
                        ->where('IsActive', true)
                        ->exists();

                    if ($exists || $existsAsParent) {
                        return response()->json([
                            'success' => false,
                            'message' => 'This Position is still used by another related data',
                            'errors' => ''
                        ], 400);
                    }
                }
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
                'AuditLog' => Carbon::now()->timestamp . random_numbersu(5),
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
                'AuditLog' => Carbon::now()->timestamp . random_numbersu(5),
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

            // ✅ Jika ingin mengaktifkan, pastikan parent aktif
            if ($newStatus && $position->ParentPositionID) {
                $parent = Position::find($position->ParentPositionID);
                if ($parent && !$parent->IsActive) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The parent position must be activated first.'
                    ], 400);
                }
            }

            // ✅ Jika ingin menonaktifkan, pastikan tidak dipakai oleh child atau employee
            if (!$newStatus) {
                $hasActiveChildren = Position::where('ParentPositionID', $id)
                    ->where('IsActive', true)
                    ->where('IsDelete', false)
                    ->exists();

                $usedByEmployee = EmployeePosition::where('PositionID', $id)
                    ->where('IsActive', true)
                    ->where('IsDelete', false)
                    ->exists();

                if ($hasActiveChildren || $usedByEmployee) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This position cannot be deactivated because it is still used by active related data (children or employee position).'
                    ], 400);
                }
            }

            $position->update([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'IsActive' => $newStatus,
            ]);

            $action = $newStatus ? 'activated' : 'deactivated';

            // Create audit log
            AuditLog::create([
                'AuditLog' => Carbon::now()->timestamp . random_numbersu(5),
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

    /**
 * Get position hierarchy tree (nested children)
 * GET /api/positions/hierarchy?organization_id={id}&include_inactive=false
 */
public function getHierarchy(Request $request)
{
    $organizationId = $request->input('organization_id');
    $includeInactive = $request->input('include_inactive', false);

    $query = Position::with(['positionLevel']);

    if ($organizationId) {
        $query->where('OrganizationID', $organizationId);
    }

    if (!$includeInactive) {
        $query->where('IsActive', true);
    }

    $query->where('IsDelete', false);

    // Get root positions (where ParentPositionID = PositionID)
    $rootPositions = $query->whereColumn('ParentPositionID', 'PositionID')->get();

    $tree = [];
    foreach ($rootPositions as $rootPosition) {
        $tree[] = $this->buildPositionHierarchy($rootPosition, $organizationId, $includeInactive);
    }

    return response()->json([
        'success' => true,
        'message' => 'Position hierarchy retrieved successfully',
        'data' => $tree
    ], 200);
}

/**
 * Build position hierarchy recursively with employee count
 * Private helper method for getHierarchy
 */
private function buildPositionHierarchy($position, $organizationId = null, $includeInactive = false, &$visited = [])
{
    // Prevent infinite loop
    if (in_array($position->PositionID, $visited)) {
        return null;
    }
    
    $visited[] = $position->PositionID;

    // Count active employees in this position
    $employeeCount = EmployeePosition::where('PositionID', $position->PositionID)
        ->active()
        ->whereNull('EndDate')
        ->count();

    // Get child positions
    $childQuery = Position::with(['positionLevel'])
        ->where('ParentPositionID', $position->PositionID)
        ->where('PositionID', '!=', $position->PositionID)
        ->where('IsDelete', false);

    if ($organizationId) {
        $childQuery->where('OrganizationID', $organizationId);
    }

    if (!$includeInactive) {
        $childQuery->where('IsActive', true);
    }

    $childPositions = $childQuery->get();

    $children = [];
    foreach ($childPositions as $childPosition) {
        $childNode = $this->buildPositionHierarchy($childPosition, $organizationId, $includeInactive, $visited);
        if ($childNode) {
            $children[] = $childNode;
        }
    }

    return [
        'PositionID' => $position->PositionID,
        'PositionName' => $position->PositionName,
        'PositionLevel' => [
            'PositionLevelID' => $position->positionLevel->PositionLevelID ?? null,
            'PositionLevelName' => $position->positionLevel->PositionLevelName ?? null,
        ],
        'LevelNo' => $position->LevelNo,
        'ParentPositionID' => $position->ParentPositionID,
        'IsChild' => $position->IsChild,
        'IsActive' => $position->IsActive,
        'RequirementQuantity' => $position->RequirementQuantity,
        'EmployeeCount' => $employeeCount,
        'Children' => $children,
    ];
}

/**
 * Get position detail with full hierarchy context
 * GET /api/positions/{id}/hierarchy
 */
public function getPositionHierarchy(Request $request, $id)
{
    $position = Position::with(['positionLevel', 'organization'])
        ->find($id);

    if (!$position) {
        return response()->json([
            'success' => false,
            'message' => 'Position not found'
        ], 404);
    }

    $organizationId = $position->OrganizationID;

    // Get parent chain (upward)
    $parents = $this->getPositionParentChain($position, $organizationId);

    // Get children tree (downward)
    $children = $this->buildPositionHierarchy($position, $organizationId, false);
    $childrenArray = $children['Children'] ?? [];

    // Get employees in this position
    $employeesInPosition = EmployeePosition::with(['employee.user'])
        ->where('PositionID', $position->PositionID)
        ->active()
        ->whereNull('EndDate')
        ->get();

    $employees = $employeesInPosition->map(function ($empPos) {
        return [
            'EmployeeID' => $empPos->employee->EmployeeID,
            'FullName' => $empPos->employee->user->FullName ?? null,
            'Email' => $empPos->employee->user->Email ?? null,
            'StartDate' => $empPos->StartDate,
        ];
    });

    return response()->json([
        'success' => true,
        'message' => 'Position hierarchy retrieved successfully',
        'data' => [
            'Position' => [
                'PositionID' => $position->PositionID,
                'PositionName' => $position->PositionName,
                'PositionLevel' => [
                    'PositionLevelID' => $position->positionLevel->PositionLevelID ?? null,
                    'PositionLevelName' => $position->positionLevel->PositionLevelName ?? null,
                ],
                'LevelNo' => $position->LevelNo,
                'IsActive' => $position->IsActive,
                'RequirementQuantity' => $position->RequirementQuantity,
            ],
            'Employees' => $employees,
            'EmployeeCount' => $employees->count(),
            'Parents' => $parents,
            'Children' => $childrenArray,
            'OrganizationID' => $organizationId,
            'OrganizationName' => $position->organization->OrganizationName ?? null,
        ]
    ], 200);
}

/**
 * Get parent chain of a position
 * Private helper method for getPositionHierarchy
 */
private function getPositionParentChain($position, $organizationId, &$visited = [])
{
    $parents = [];
    
    // Prevent infinite loop
    if (in_array($position->PositionID, $visited)) {
        return $parents;
    }
    
    $visited[] = $position->PositionID;

    // Check if has parent and parent is not itself
    if ($position->ParentPositionID && $position->ParentPositionID != $position->PositionID) {
        $parentPosition = Position::with(['positionLevel'])
            ->where('PositionID', $position->ParentPositionID)
            ->where('OrganizationID', $organizationId)
            ->where('IsDelete', false)
            ->first();

        if ($parentPosition) {
            $employeeCount = EmployeePosition::where('PositionID', $parentPosition->PositionID)
                ->active()
                ->whereNull('EndDate')
                ->count();

            $parents[] = [
                'PositionID' => $parentPosition->PositionID,
                'PositionName' => $parentPosition->PositionName,
                'PositionLevel' => [
                    'PositionLevelID' => $parentPosition->positionLevel->PositionLevelID ?? null,
                    'PositionLevelName' => $parentPosition->positionLevel->PositionLevelName ?? null,
                ],
                'LevelNo' => $parentPosition->LevelNo,
                'EmployeeCount' => $employeeCount,
            ];

            // Recursively get parent's parent
            $upperParents = $this->getPositionParentChain($parentPosition, $organizationId, $visited);
            $parents = array_merge($parents, $upperParents);
        }
    }

    return $parents;
}
}

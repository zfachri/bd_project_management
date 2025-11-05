<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PositionLevel;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class PositionLevelController extends Controller
{
    /**
     * Get all position levels
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $search = $request->input('search');

        $query = PositionLevel::query();

        if ($search) {
            $query->where('PositionLevelName', 'like', "%{$search}%");
        }

        $positionLevels = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Position levels retrieved successfully',
            'data' => $positionLevels
        ], 200);
    }

    /**
     * Get all position levels without pagination
     */
    public function all()
    {
        $positionLevels = PositionLevel::all();

        return response()->json([
            'success' => true,
            'message' => 'Position levels retrieved successfully',
            'data' => $positionLevels
        ], 200);
    }

    /**
     * Get single position level
     */
    public function show($id)
    {
        $positionLevel = PositionLevel::find($id);

        if (!$positionLevel) {
            return response()->json([
                'success' => false,
                'message' => 'Position level not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Position level retrieved successfully',
            'data' => $positionLevel
        ], 200);
    }

    /**
     * Create new position level
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'PositionLevelName' => 'required|string|max:100',
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

            $positionLevel = PositionLevel::create([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'PositionLevelName' => $request->PositionLevelName,
            ]);

            // Create audit log
            AuditLog::create([
                'AuditLogID'=> Carbon::now()->timsetamp.random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'PositionLevel',
                'ReferenceRecordID' => $positionLevel->PositionLevelID,
                'Data' => json_encode([
                    'PositionLevelName' => $positionLevel->PositionLevelName,
                ]),
                'Note' => 'Position level created'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Position level created successfully',
                'data' => $positionLevel
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create position level',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update position level
     */
    public function update(Request $request, $id)
    {
        $positionLevel = PositionLevel::find($id);

        if (!$positionLevel) {
            return response()->json([
                'success' => false,
                'message' => 'Position level not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'PositionLevelName' => 'required|string|max:100',
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
                'PositionLevelName' => $positionLevel->PositionLevelName,
            ];

            $positionLevel->update([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'PositionLevelName' => $request->PositionLevelName,
            ]);

            // Create audit log
            AuditLog::create([
                'AuditLogID'=> Carbon::now()->timsetamp.random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'PositionLevel',
                'ReferenceRecordID' => $positionLevel->PositionLevelID,
                'Data' => json_encode([
                    'Old' => $oldData,
                    'New' => [
                        'PositionLevelName' => $positionLevel->PositionLevelName,
                    ]
                ]),
                'Note' => 'Position level updated'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Position level updated successfully',
                'data' => $positionLevel
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update position level',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete position level
     */
    // public function destroy(Request $request, $id)
    // {
    //     $positionLevel = PositionLevel::find($id);

    //     if (!$positionLevel) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Position level not found'
    //         ], 404);
    //     }

    //     try {
    //         $timestamp = Carbon::now()->timestamp;
    //         $authUserId = $request->auth_user_id;

    //         // Create audit log before deletion
    //         AuditLog::create([
    //             'AuditLogID'=> Carbon::now()->timsetamp.random_numbersu(5),
    //             'AtTimeStamp' => $timestamp,
    //             'ByUserID' => $authUserId,
    //             'OperationCode' => 'D',
    //             'ReferenceTable' => 'position_level',
    //             'ReferenceRecordID' => $positionLevel->PositionLevelID,
    //             'Data' => json_encode([
    //                 'PositionLevelName' => $positionLevel->PositionLevelName,
    //             ]),
    //             'Note' => 'Position level deleted'
    //         ]);

    //         $positionLevel->delete();

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Position level deleted successfully'
    //         ], 200);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to delete position level',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }
}
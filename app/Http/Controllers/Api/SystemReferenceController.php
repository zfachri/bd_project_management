<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemReference;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SystemReferenceController extends Controller
{
    public function index(Request $request)
    {
        if ($adminCheck = $this->ensureAdmin($request)) {
            return $adminCheck;
        }

        $perPage = (int) $request->input('per_page', 15);
        if ($perPage <= 0) {
            $perPage = 15;
        }
        $perPage = min($perPage, 100);

        $query = SystemReference::query();

        if ($request->filled('reference_name')) {
            $query->where('ReferenceName', 'like', '%' . $request->input('reference_name') . '%');
        }

        if ($request->filled('field_name')) {
            $query->where('FieldName', 'like', '%' . $request->input('field_name') . '%');
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('ReferenceName', 'like', '%' . $search . '%')
                    ->orWhere('FieldName', 'like', '%' . $search . '%')
                    ->orWhere('FieldValue', 'like', '%' . $search . '%');
            });
        }

        $references = $query
            ->orderBy('ReferenceName')
            ->orderBy('FieldName')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'System references retrieved successfully',
            'data' => $references,
        ], 200);
    }

    public function show(Request $request, $id)
    {
        if ($adminCheck = $this->ensureAdmin($request)) {
            return $adminCheck;
        }

        $reference = SystemReference::find($id);
        if (!$reference) {
            return response()->json([
                'success' => false,
                'message' => 'System reference not found',
            ], 404);
        }

        $referenceData = $reference->toArray();
        $fieldValue = (string) ($referenceData['FieldValue'] ?? '');
        preg_match_all('/{{\s*([a-zA-Z0-9_]+)\s*}}/', $fieldValue, $matches);
        $variables = array_values(array_unique($matches[1] ?? []));
        $referenceData['Variables'] = $variables;

        return response()->json([
            'success' => true,
            'message' => 'System reference retrieved successfully',
            'data' => $referenceData,
        ], 200);
    }

    public function store(Request $request)
    {
        if ($adminCheck = $this->ensureAdmin($request)) {
            return $adminCheck;
        }

        $validator = Validator::make($request->all(), [
            'ReferenceName' => 'required|string|max:100',
            'FieldName' => 'required|string|max:100',
            'FieldValue' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;
            $referenceId = $timestamp . random_numbersu(5);

            $reference = SystemReference::create([
                'SystemReferenceID' => $referenceId,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceName' => $request->ReferenceName,
                'FieldName' => $request->FieldName,
                'FieldValue' => $request->FieldValue,
            ]);

            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'SystemReference',
                'ReferenceRecordID' => $referenceId,
                'Data' => json_encode($reference->toArray()),
                'Note' => 'System reference created',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'System reference created successfully',
                'data' => $reference,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create system reference',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        if ($adminCheck = $this->ensureAdmin($request)) {
            return $adminCheck;
        }

        $reference = SystemReference::find($id);
        if (!$reference) {
            return response()->json([
                'success' => false,
                'message' => 'System reference not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'ReferenceName' => 'sometimes|required|string|max:100',
            'FieldName' => 'sometimes|required|string|max:100',
            'FieldValue' => 'sometimes|required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (
            !$request->has('ReferenceName')
            && !$request->has('FieldName')
            && !$request->has('FieldValue')
        ) {
            return response()->json([
                'success' => false,
                'message' => 'No data to update',
            ], 422);
        }

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;
            $oldData = $reference->toArray();

            $updateData = [
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
            ];

            if ($request->has('ReferenceName')) {
                $updateData['ReferenceName'] = $request->ReferenceName;
            }
            if ($request->has('FieldName')) {
                $updateData['FieldName'] = $request->FieldName;
            }
            if ($request->has('FieldValue')) {
                $updateData['FieldValue'] = $request->FieldValue;
            }

            $reference->update($updateData);

            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'SystemReference',
                'ReferenceRecordID' => $reference->SystemReferenceID,
                'Data' => json_encode([
                    'old' => $oldData,
                    'new' => $reference->fresh()->toArray(),
                ]),
                'Note' => 'System reference updated',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'System reference updated successfully',
                'data' => $reference->fresh(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update system reference',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        if ($adminCheck = $this->ensureAdmin($request)) {
            return $adminCheck;
        }

        $reference = SystemReference::find($id);
        if (!$reference) {
            return response()->json([
                'success' => false,
                'message' => 'System reference not found',
            ], 404);
        }

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;
            $oldData = $reference->toArray();

            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
                'ReferenceTable' => 'SystemReference',
                'ReferenceRecordID' => $reference->SystemReferenceID,
                'Data' => json_encode($oldData),
                'Note' => 'System reference deleted',
            ]);

            $reference->delete();

            return response()->json([
                'success' => true,
                'message' => 'System reference deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete system reference',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function ensureAdmin(Request $request)
    {
        $authUser = $request->auth_user;
        if (!$authUser || !(bool) ($authUser->IsAdministrator ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Only administrator can access this',
            ], 403);
        }

        return null;
    }
}

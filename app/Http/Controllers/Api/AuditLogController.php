<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AuditLogController extends Controller
{
    /**
     * List all audit logs (admin only) with pagination and filters.
     */
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

        $query = AuditLog::with('user');

        if ($request->filled('ReferenceTable')) {
            $query->where('ReferenceTable', $request->input('ReferenceTable'));
        }

        if ($request->filled('ReferenceRecordID')) {
            $query->where('ReferenceRecordID', $request->input('ReferenceRecordID'));
        }

        if ($request->filled('OperationCode')) {
            $query->where('OperationCode', $request->input('OperationCode'));
        }

        if ($request->filled('ByUserID')) {
            $query->where('ByUserID', $request->input('ByUserID'));
        }

        if ($request->filled('from_timestamp')) {
            $query->where('AtTimeStamp', '>=', (int) $request->input('from_timestamp'));
        }

        if ($request->filled('to_timestamp')) {
            $query->where('AtTimeStamp', '<=', (int) $request->input('to_timestamp'));
        }

        if ($request->filled('note')) {
            $query->where('Note', 'like', '%' . $request->input('note') . '%');
        }

        $logs = $query->orderBy('AtTimeStamp', 'desc')->paginate($perPage);

        $logs->getCollection()->transform(function ($log) {
            $decodedData = null;
            if (!empty($log->Data)) {
                $decodedData = json_decode($log->Data, true);
            }

            return [
                'AuditLogID' => $log->AuditLogID,
                'AtTimeStamp' => $log->AtTimeStamp,
                'AtDateTime' => Carbon::createFromTimestamp($log->AtTimeStamp)->format('Y-m-d H:i:s'),
                'ByUserID' => $log->ByUserID,
                'ByUserName' => optional($log->user)->FullName,
                'OperationCode' => $log->OperationCode,
                'ReferenceTable' => $log->ReferenceTable,
                'ReferenceRecordID' => $log->ReferenceRecordID,
                'Note' => $log->Note,
                'Data' => $decodedData,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Audit logs retrieved successfully',
            'data' => $logs,
        ], 200);
    }

    /**
     * Get audit log detail by AuditLogID (admin only).
     */
    public function show(Request $request, $auditLogId)
    {
        if ($adminCheck = $this->ensureAdmin($request)) {
            return $adminCheck;
        }

        $log = AuditLog::with('user')->find($auditLogId);

        if (!$log) {
            return response()->json([
                'success' => false,
                'message' => 'Audit log not found',
            ], 404);
        }

        $decodedData = null;
        if (!empty($log->Data)) {
            $decodedData = json_decode($log->Data, true);
        }

        return response()->json([
            'success' => true,
            'message' => 'Audit log retrieved successfully',
            'data' => [
                'AuditLogID' => $log->AuditLogID,
                'AtTimeStamp' => $log->AtTimeStamp,
                'AtDateTime' => Carbon::createFromTimestamp($log->AtTimeStamp)->format('Y-m-d H:i:s'),
                'ByUserID' => $log->ByUserID,
                'ByUserName' => optional($log->user)->FullName,
                'OperationCode' => $log->OperationCode,
                'ReferenceTable' => $log->ReferenceTable,
                'ReferenceRecordID' => $log->ReferenceRecordID,
                'Note' => $log->Note,
                'Data' => $decodedData,
            ],
        ], 200);
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


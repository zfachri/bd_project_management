<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ProjectFilterController extends Controller
{
    /**
     * List Project for Task Filter (NO PAGINATION)
     *
     * GET /projects/for-task-filter
     */
    public function index(Request $request)
    {
        $authUser   = $request->auth_user;
        $authUserId = $authUser['UserID'];
        $isAdmin    = $authUser['IsAdministrator'] ?? false;

        $search = $request->get('search');

        $query = Project::query()
            ->select([
                'projects.id',
                'projects.name',
                'projects.owner_id',
                'projects.created_at',
            ])
            ->where('projects.is_active', 1);

        /**
         * 🔐 Role-based filter
         */
        if (!$isAdmin) {
            $query->where(function ($q) use ($authUserId) {
                $q->where('projects.owner_id', $authUserId)
                  ->orWhereExists(function ($sub) use ($authUserId) {
                      $sub->select(DB::raw(1))
                          ->from('project_members')
                          ->whereColumn('project_members.project_id', 'projects.id')
                          ->where('project_members.user_id', $authUserId);
                  });
            });
        }

        /**
         * 🔍 Optional search (project name)
         */
        if ($search) {
            $query->where('projects.name', 'LIKE', "%{$search}%");
        }

        $projects = $query
            ->orderBy('projects.name', 'ASC')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Project list for task filter',
            'data'    => $projects,
        ]);
    }
}

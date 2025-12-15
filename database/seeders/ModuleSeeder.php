<?php 
/**
 * Seeder: Module Seeder
 * File: database/seeders/ModuleSeeder.php
 */

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ModuleSeeder extends Seeder
{
    public function run()
    {
        $timestamp = Carbon::now()->timestamp;
        $systemUserId = 1; // System user

        $modules = [
            [
                'ModuleID' => $timestamp . '00001',
                'ModuleName' => 'JobDescription',
                'DisplayName' => 'Job Description',
                'Description' => 'Manage job descriptions and job requirements',
                'SortOrder' => 1,
            ],
            [
                'ModuleID' => $timestamp . '00002',
                'ModuleName' => 'Project',
                'DisplayName' => 'Project Management',
                'Description' => 'Manage projects and project tasks',
                'SortOrder' => 2,
            ],
            [
                'ModuleID' => $timestamp . '00003',
                'ModuleName' => 'Document',
                'DisplayName' => 'Document Management',
                'Description' => 'Manage documents and files',
                'SortOrder' => 3,
            ],
            [
                'ModuleID' => $timestamp . '00004',
                'ModuleName' => 'DocumentSubmission',
                'DisplayName' => 'Document Submission',
                'Description' => 'Submit and approve document requests',
                'SortOrder' => 4,
            ],
            [
                'ModuleID' => $timestamp . '00005',
                'ModuleName' => 'DocumentRevision',
                'DisplayName' => 'Document Revision',
                'Description' => 'Request and approve document revisions',
                'SortOrder' => 5,
            ],
            [
                'ModuleID' => $timestamp . '00006',
                'ModuleName' => 'Employee',
                'DisplayName' => 'Employee Management',
                'Description' => 'Manage employee data and positions',
                'SortOrder' => 6,
            ],
            [
                'ModuleID' => $timestamp . '00007',
                'ModuleName' => 'Organization',
                'DisplayName' => 'Organization Management',
                'Description' => 'Manage organization structure',
                'SortOrder' => 7,
            ],
            [
                'ModuleID' => $timestamp . '00008',
                'ModuleName' => 'Position',
                'DisplayName' => 'Position Management',
                'Description' => 'Manage positions and position hierarchy',
                'SortOrder' => 8,
            ],
            [
                'ModuleID' => $timestamp . '00009',
                'ModuleName' => 'User',
                'DisplayName' => 'User Management',
                'Description' => 'Manage users and access control',
                'SortOrder' => 9,
            ],
            [
                'ModuleID' => $timestamp . '00010',
                'ModuleName' => 'Role',
                'DisplayName' => 'Role & Permission',
                'Description' => 'Manage roles and permissions',
                'SortOrder' => 10,
            ],
        ];

        foreach ($modules as $module) {
            DB::table('Module')->insert([
                'ModuleID' => $module['ModuleID'],
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $systemUserId,
                'OperationCode' => 'I',
                'ModuleName' => $module['ModuleName'],
                'DisplayName' => $module['DisplayName'],
                'Description' => $module['Description'],
                'SortOrder' => $module['SortOrder'],
                'IsActive' => true,
                'IsDelete' => false,
            ]);
        }

        $this->command->info('Modules seeded successfully!');
    }
}
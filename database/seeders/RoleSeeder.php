<?php 
/**
 * Seeder: Role Seeder (Sample Data)
 * File: database/seeders/RoleSeeder.php
 */

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RoleSeeder extends Seeder
{
    public function run()
    {
        $timestamp = Carbon::now()->timestamp;
        $systemUserId = 1;

        $roles = [
            [
                'RoleID' => $timestamp . '00001',
                'RoleName' => 'Super Admin',
                'Description' => 'Full access to all modules and data',
            ],
            [
                'RoleID' => $timestamp . '00002',
                'RoleName' => 'Manager',
                'Description' => 'Manager level access with team management capabilities',
            ],
            [
                'RoleID' => $timestamp . '00003',
                'RoleName' => 'Supervisor',
                'Description' => 'Supervisor level access with limited team management',
            ],
            [
                'RoleID' => $timestamp . '00004',
                'RoleName' => 'Staff',
                'Description' => 'Basic staff access to own data only',
            ],
            [
                'RoleID' => $timestamp . '00005',
                'RoleName' => 'Viewer',
                'Description' => 'Read-only access to assigned modules',
            ],
        ];

        foreach ($roles as $role) {
            DB::table('Role')->insert([
                'RoleID' => $role['RoleID'],
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $systemUserId,
                'OperationCode' => 'I',
                'RoleName' => $role['RoleName'],
                'Description' => $role['Description'],
                'IsActive' => true,
                'IsDelete' => false,
            ]);
        }

        $this->command->info('Roles seeded successfully!');
    }
}
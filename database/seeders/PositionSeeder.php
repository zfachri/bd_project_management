<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PositionSeeder extends Seeder
{
    public function run(): void
    {
        $timestamp = Carbon::now()->timestamp;

        DB::transaction(function () use ($timestamp) {
            // Get Position Level IDs (assuming they exist from PositionLevelSeeder)
            $positionLevels = DB::table('PositionLevel')->pluck('PositionLevelID', 'PositionLevelName');

            $positions = [
                // BUSINESS DEVELOPMENT (Root - 100000000010001)
                [
                    'PositionID' => 200000000010001,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010001,
                    'ParentPositionID' => 200000000010001, // Self reference (root)
                    'LevelNo' => 1,
                    'IsChild' => 0,
                    'PositionName' => 'GENERAL MANAGER',
                    'PositionLevelID' => $positionLevels['GENERAL MANAGER'],
                    'RequirementQuantity' => 1,
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // MARKETING Organization (100000000010002)
                [
                    'PositionID' => 200000000010002,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010002,
                    'ParentPositionID' => 200000000010001, // Parent: General Manager
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'PositionName' => 'MARKETING MANAGER',
                    'PositionLevelID' => $positionLevels['MANAGER'],
                    'RequirementQuantity' => 1,
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'PositionID' => 200000000010003,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010008, // Sub Marketing
                    'ParentPositionID' => 200000000010002,
                    'LevelNo' => 3,
                    'IsChild' => 1,
                    'PositionName' => 'MARKETING SUPERVISOR',
                    'PositionLevelID' => $positionLevels['SUPERVISOR'],
                    'RequirementQuantity' => 2,
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'PositionID' => 200000000010004,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010008,
                    'ParentPositionID' => 200000000010003,
                    'LevelNo' => 4,
                    'IsChild' => 1,
                    'PositionName' => 'MARKETING STAFF',
                    'PositionLevelID' => $positionLevels['STAFF'],
                    'RequirementQuantity' => 5,
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // R&D Organization (100000000010003)
                [
                    'PositionID' => 200000000010005,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010003,
                    'ParentPositionID' => 200000000010001,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'PositionName' => 'R&D MANAGER',
                    'PositionLevelID' => $positionLevels['MANAGER'],
                    'RequirementQuantity' => 1,
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'PositionID' => 200000000010006,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010009, // R&D Food
                    'ParentPositionID' => 200000000010005,
                    'LevelNo' => 3,
                    'IsChild' => 1,
                    'PositionName' => 'R&D FOOD SUPERVISOR',
                    'PositionLevelID' => $positionLevels['SUPERVISOR'],
                    'RequirementQuantity' => 1,
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'PositionID' => 200000000010007,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010009,
                    'ParentPositionID' => 200000000010006,
                    'LevelNo' => 4,
                    'IsChild' => 1,
                    'PositionName' => 'R&D FOOD STAFF',
                    'PositionLevelID' => $positionLevels['STAFF'],
                    'RequirementQuantity' => 3,
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // SALES & DISTRIBUTION (100000000010004)
                [
                    'PositionID' => 200000000010008,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010004,
                    'ParentPositionID' => 200000000010001,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'PositionName' => 'SALES MANAGER',
                    'PositionLevelID' => $positionLevels['MANAGER'],
                    'RequirementQuantity' => 1,
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'PositionID' => 200000000010009,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010011, // Sub Sales
                    'ParentPositionID' => 200000000010008,
                    'LevelNo' => 3,
                    'IsChild' => 1,
                    'PositionName' => 'SALES SUPERVISOR',
                    'PositionLevelID' => $positionLevels['SUPERVISOR'],
                    'RequirementQuantity' => 3,
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'PositionID' => 200000000010010,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010011,
                    'ParentPositionID' => 200000000010009,
                    'LevelNo' => 4,
                    'IsChild' => 1,
                    'PositionName' => 'SALES STAFF',
                    'PositionLevelID' => $positionLevels['STAFF'],
                    'RequirementQuantity' => 10,
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // SUPPORT - Finance (100000000010006)
                [
                    'PositionID' => 200000000010011,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010006,
                    'ParentPositionID' => 200000000010001,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'PositionName' => 'SUPPORT MANAGER',
                    'PositionLevelID' => $positionLevels['MANAGER'],
                    'RequirementQuantity' => 1,
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'PositionID' => 200000000010012,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010018, // Finance
                    'ParentPositionID' => 200000000010011,
                    'LevelNo' => 3,
                    'IsChild' => 1,
                    'PositionName' => 'FINANCE SUPERVISOR',
                    'PositionLevelID' => $positionLevels['SUPERVISOR'],
                    'RequirementQuantity' => 2,
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'PositionID' => 200000000010013,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010018,
                    'ParentPositionID' => 200000000010012,
                    'LevelNo' => 4,
                    'IsChild' => 1,
                    'PositionName' => 'FINANCE STAFF',
                    'PositionLevelID' => $positionLevels['STAFF'],
                    'RequirementQuantity' => 5,
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
            ];

            DB::table('Position')->insert($positions);
        });
    }
}
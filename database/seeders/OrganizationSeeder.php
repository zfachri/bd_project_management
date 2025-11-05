<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $timestamp = Carbon::now()->timestamp;

        DB::transaction(function () use ($timestamp) {
            $userId1 = DB::table('Organization')->insert([
                [
                    'OrganizationID' => 100000000010001,
                    'AtTimeStamp' => 1762272385,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'U',
                    'ParentOrganizationID' => 100000000010001,
                    'LevelNo' => 1,
                    'IsChild' => 0,
                    'OrganizationName' => 'BUSINESS DEVELOPMENT',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010002,
                    'AtTimeStamp' => 1762243289,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010002,
                    'LevelNo' => 1,
                    'IsChild' => 0,
                    'OrganizationName' => 'MARKETING',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010003,
                    'AtTimeStamp' => 1762243337,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010003,
                    'LevelNo' => 1,
                    'IsChild' => 0,
                    'OrganizationName' => 'RESEARCH & DEVELOPMENT',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010004,
                    'AtTimeStamp' => 1762243350,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010004,
                    'LevelNo' => 1,
                    'IsChild' => 0,
                    'OrganizationName' => 'SALES & DISTRIBUTION',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010005,
                    'AtTimeStamp' => 1762243373,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010005,
                    'LevelNo' => 1,
                    'IsChild' => 0,
                    'OrganizationName' => 'SUPPLY CHAIN MANAGEMENT',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010006,
                    'AtTimeStamp' => 1762244128,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010006,
                    'LevelNo' => 1,
                    'IsChild' => 0,
                    'OrganizationName' => 'SUPPORT',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010007,
                    'AtTimeStamp' => 1762248569,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010001,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'OrganizationName' => 'BUSINESS DEVELOPMENT',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010008,
                    'AtTimeStamp' => 1762248594,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010002,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'OrganizationName' => 'MARKETING',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010009,
                    'AtTimeStamp' => 1762248603,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010003,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'OrganizationName' => 'RESEARCH & DEVELOPMENT (FOOD)',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010010,
                    'AtTimeStamp' => 1762248612,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010003,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'OrganizationName' => 'RESEARCH & DEVELOPMENT (NON FOOD)',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010011,
                    'AtTimeStamp' => 1762248628,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010004,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'OrganizationName' => 'SALES & DISTRIBUTION',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010012,
                    'AtTimeStamp' => 1762248638,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010005,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'OrganizationName' => 'PPIC',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010013,
                    'AtTimeStamp' => 1762248645,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010005,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'OrganizationName' => 'PROCUREMENT',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010014,
                    'AtTimeStamp' => 1762248652,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010005,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'OrganizationName' => 'SUPPLY CHAIN MANAGEMENT',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010015,
                    'AtTimeStamp' => 1762248660,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010005,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'OrganizationName' => 'TOLL MANUFACTURING & QUALITY',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010016,
                    'AtTimeStamp' => 1762248682,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010006,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'OrganizationName' => 'BUSINESS DEVELOPMENT',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010017,
                    'AtTimeStamp' => 1762248690,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010006,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'OrganizationName' => 'DATA ANALYST',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010018,
                    'AtTimeStamp' => 1762248697,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010006,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'OrganizationName' => 'FINANCE AND ACCOUNTING',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010019,
                    'AtTimeStamp' => 1762248707,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010006,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'OrganizationName' => 'MARKET RESEARCH',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
                [
                    'OrganizationID' => 100000000010020,
                    'AtTimeStamp' => 1762248714,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'ParentOrganizationID' => 100000000010006,
                    'LevelNo' => 2,
                    'IsChild' => 1,
                    'OrganizationName' => 'STRATEGIC MANAGEMENT OFFICE',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ]
            ]);
        });
    }
}

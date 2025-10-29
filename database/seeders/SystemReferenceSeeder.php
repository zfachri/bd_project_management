<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SystemReferenceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $timestamp = Carbon::now()->timestamp;
        $userId = 1; // Admin user

        $references = [
            [
                'ReferenceName' => 'Organization',
                'FieldName' => 'OrganizationLOV',
                'FieldValue' => json_encode([
                    'ListOfValue' => [
                        ['LevelNo' => 1, 'OrganizationLabel' => 'Division'],
                        ['LevelNo' => 2, 'OrganizationLabel' => 'Department']
                    ]
                ])
            ],
            [
                'ReferenceName' => 'User',
                'FieldName' => 'OTPExpiry',
                'FieldValue' => '60'
            ],
            [
                'ReferenceName' => 'System',
                'FieldName' => 'AuditLogDuration',
                'FieldValue' => '360'
            ],
            [
                'ReferenceName' => 'System',
                'FieldName' => 'LoginLogDuration',
                'FieldValue' => '360'
            ],
            [
                'ReferenceName' => 'System',
                'FieldName' => 'MaximumLoginAttemptCounter',
                'FieldValue' => '5'
            ],
            [
                'ReferenceName' => 'System',
                'FieldName' => 'NonEmployee',
                'FieldValue' => '1000000'
            ],
        ];

        foreach ($references as $reference) {
            DB::table('system_reference')->insert([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $userId,
                'OperationCode' => 'I',
                'ReferenceName' => $reference['ReferenceName'],
                'FieldName' => $reference['FieldName'],
                'FieldValue' => $reference['FieldValue'],
            ]);
        }
    }
}

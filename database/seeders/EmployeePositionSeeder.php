<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class EmployeePositionSeeder extends Seeder
{
    public function run(): void
    {
        $timestamp = Carbon::now()->timestamp;

        DB::transaction(function () use ($timestamp) {
            $employeePositions = [
                // General Manager
                [
                    'EmployeePositionID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010001,
                    'PositionID' => 200000000010001,
                    'EmployeeID' => 1000001,
                    'StartDate' => '2020-01-15',
                    'EndDate' => null,
                    'Note' => 'Initial position assignment',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // Marketing Manager
                [
                    'EmployeePositionID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010002,
                    'PositionID' => 200000000010002,
                    'EmployeeID' => 1000002,
                    'StartDate' => '2020-03-01',
                    'EndDate' => null,
                    'Note' => 'Initial position assignment',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // Marketing Supervisor
                [
                    'EmployeePositionID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010008,
                    'PositionID' => 200000000010003,
                    'EmployeeID' => 1000003,
                    'StartDate' => '2021-02-01',
                    'EndDate' => null,
                    'Note' => 'Initial position assignment',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // Marketing Staff 1
                [
                    'EmployeePositionID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010008,
                    'PositionID' => 200000000010004,
                    'EmployeeID' => 1000004,
                    'StartDate' => '2022-01-10',
                    'EndDate' => null,
                    'Note' => 'Initial position assignment',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // Marketing Staff 2
                [
                    'EmployeePositionID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010008,
                    'PositionID' => 200000000010004,
                    'EmployeeID' => 1000005,
                    'StartDate' => '2022-06-15',
                    'EndDate' => null,
                    'Note' => 'Initial position assignment',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // R&D Manager
                [
                    'EmployeePositionID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010003,
                    'PositionID' => 200000000010005,
                    'EmployeeID' => 1000006,
                    'StartDate' => '2020-02-01',
                    'EndDate' => null,
                    'Note' => 'Initial position assignment',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // R&D Food Supervisor
                [
                    'EmployeePositionID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010009,
                    'PositionID' => 200000000010006,
                    'EmployeeID' => 1000007,
                    'StartDate' => '2021-03-01',
                    'EndDate' => null,
                    'Note' => 'Initial position assignment',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // R&D Food Staff
                [
                    'EmployeePositionID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010009,
                    'PositionID' => 200000000010007,
                    'EmployeeID' => 1000008,
                    'StartDate' => '2022-02-01',
                    'EndDate' => null,
                    'Note' => 'Initial position assignment',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // Sales Manager
                [
                    'EmployeePositionID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010004,
                    'PositionID' => 200000000010008,
                    'EmployeeID' => 1000009,
                    'StartDate' => '2020-04-01',
                    'EndDate' => null,
                    'Note' => 'Initial position assignment',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // Sales Supervisor
                [
                    'EmployeePositionID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010011,
                    'PositionID' => 200000000010009,
                    'EmployeeID' => 1000010,
                    'StartDate' => '2021-05-01',
                    'EndDate' => null,
                    'Note' => 'Initial position assignment',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // Sales Staff 1
                [
                    'EmployeePositionID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010011,
                    'PositionID' => 200000000010010,
                    'EmployeeID' => 1000011,
                    'StartDate' => '2022-03-15',
                    'EndDate' => null,
                    'Note' => 'Initial position assignment',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // Sales Staff 2
                [
                    'EmployeePositionID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010011,
                    'PositionID' => 200000000010010,
                    'EmployeeID' => 1000012,
                    'StartDate' => '2023-01-10',
                    'EndDate' => null,
                    'Note' => 'Initial position assignment',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // Support Manager
                [
                    'EmployeePositionID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010006,
                    'PositionID' => 200000000010011,
                    'EmployeeID' => 1000013,
                    'StartDate' => '2020-05-01',
                    'EndDate' => null,
                    'Note' => 'Initial position assignment',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // Finance Supervisor
                [
                    'EmployeePositionID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010018,
                    'PositionID' => 200000000010012,
                    'EmployeeID' => 1000014,
                    'StartDate' => '2021-04-01',
                    'EndDate' => null,
                    'Note' => 'Initial position assignment',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // Finance Staff 1
                [
                    'EmployeePositionID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010018,
                    'PositionID' => 200000000010013,
                    'EmployeeID' => 1000015,
                    'StartDate' => '2022-07-01',
                    'EndDate' => null,
                    'Note' => 'Initial position assignment',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],

                // Finance Staff 2
                [
                    'EmployeePositionID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010018,
                    'PositionID' => 200000000010013,
                    'EmployeeID' => 1000016,
                    'StartDate' => '2023-02-15',
                    'EndDate' => null,
                    'Note' => 'Initial position assignment',
                    'IsActive' => 1,
                    'IsDelete' => 0
                ],
            ];

            DB::table('EmployeePosition')->insert($employeePositions);
        });
    }
}
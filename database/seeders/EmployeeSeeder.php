<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $timestamp = Carbon::now()->timestamp;

        DB::transaction(function () use ($timestamp) {
            $employees = [
                // General Manager
                [
                    'EmployeeID' => 1000001,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010001,
                    'GenderCode' => 'M',
                    'DateOfBirth' => '1975-05-15',
                    'JoinDate' => '2020-01-15',
                    'ResignDate' => null,
                    'Note' => 'General Manager',
                    'IsDelete' => 0
                ],

                // Marketing Team
                [
                    'EmployeeID' => 1000002,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010002,
                    'GenderCode' => 'F',
                    'DateOfBirth' => '1985-08-20',
                    'JoinDate' => '2020-03-01',
                    'ResignDate' => null,
                    'Note' => 'Marketing Manager',
                    'IsDelete' => 0
                ],
                [
                    'EmployeeID' => 1000003,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010008,
                    'GenderCode' => 'M',
                    'DateOfBirth' => '1990-03-12',
                    'JoinDate' => '2021-02-01',
                    'ResignDate' => null,
                    'Note' => 'Marketing Supervisor',
                    'IsDelete' => 0
                ],
                [
                    'EmployeeID' => 1000004,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010008,
                    'GenderCode' => 'F',
                    'DateOfBirth' => '1995-07-25',
                    'JoinDate' => '2022-01-10',
                    'ResignDate' => null,
                    'Note' => 'Marketing Staff',
                    'IsDelete' => 0
                ],
                [
                    'EmployeeID' => 1000005,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010008,
                    'GenderCode' => 'M',
                    'DateOfBirth' => '1996-11-08',
                    'JoinDate' => '2022-06-15',
                    'ResignDate' => null,
                    'Note' => 'Marketing Staff',
                    'IsDelete' => 0
                ],

                // R&D Team
                [
                    'EmployeeID' => 1000006,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010003,
                    'GenderCode' => 'M',
                    'DateOfBirth' => '1983-04-18',
                    'JoinDate' => '2020-02-01',
                    'ResignDate' => null,
                    'Note' => 'R&D Manager',
                    'IsDelete' => 0
                ],
                [
                    'EmployeeID' => 1000007,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010009,
                    'GenderCode' => 'F',
                    'DateOfBirth' => '1988-09-22',
                    'JoinDate' => '2021-03-01',
                    'ResignDate' => null,
                    'Note' => 'R&D Food Supervisor',
                    'IsDelete' => 0
                ],
                [
                    'EmployeeID' => 1000008,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010009,
                    'GenderCode' => 'M',
                    'DateOfBirth' => '1993-12-05',
                    'JoinDate' => '2022-02-01',
                    'ResignDate' => null,
                    'Note' => 'R&D Food Staff',
                    'IsDelete' => 0
                ],

                // Sales Team
                [
                    'EmployeeID' => 1000009,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010004,
                    'GenderCode' => 'M',
                    'DateOfBirth' => '1982-06-30',
                    'JoinDate' => '2020-04-01',
                    'ResignDate' => null,
                    'Note' => 'Sales Manager',
                    'IsDelete' => 0
                ],
                [
                    'EmployeeID' => 1000010,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010011,
                    'GenderCode' => 'F',
                    'DateOfBirth' => '1989-10-14',
                    'JoinDate' => '2021-05-01',
                    'ResignDate' => null,
                    'Note' => 'Sales Supervisor',
                    'IsDelete' => 0
                ],
                [
                    'EmployeeID' => 1000011,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010011,
                    'GenderCode' => 'M',
                    'DateOfBirth' => '1994-02-28',
                    'JoinDate' => '2022-03-15',
                    'ResignDate' => null,
                    'Note' => 'Sales Staff',
                    'IsDelete' => 0
                ],
                [
                    'EmployeeID' => 1000012,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010011,
                    'GenderCode' => 'F',
                    'DateOfBirth' => '1997-08-19',
                    'JoinDate' => '2023-01-10',
                    'ResignDate' => null,
                    'Note' => 'Sales Staff',
                    'IsDelete' => 0
                ],

                // Support - Finance Team
                [
                    'EmployeeID' => 1000013,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010006,
                    'GenderCode' => 'F',
                    'DateOfBirth' => '1984-01-25',
                    'JoinDate' => '2020-05-01',
                    'ResignDate' => null,
                    'Note' => 'Support Manager',
                    'IsDelete' => 0
                ],
                [
                    'EmployeeID' => 1000014,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010018,
                    'GenderCode' => 'M',
                    'DateOfBirth' => '1987-11-03',
                    'JoinDate' => '2021-04-01',
                    'ResignDate' => null,
                    'Note' => 'Finance Supervisor',
                    'IsDelete' => 0
                ],
                [
                    'EmployeeID' => 1000015,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010018,
                    'GenderCode' => 'F',
                    'DateOfBirth' => '1992-05-17',
                    'JoinDate' => '2022-07-01',
                    'ResignDate' => null,
                    'Note' => 'Finance Staff',
                    'IsDelete' => 0
                ],
                [
                    'EmployeeID' => 1000016,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010018,
                    'GenderCode' => 'M',
                    'DateOfBirth' => '1998-09-11',
                    'JoinDate' => '2023-02-15',
                    'ResignDate' => null,
                    'Note' => 'Finance Staff',
                    'IsDelete' => 0
                ],
            ];

            DB::table('Employee')->insert($employees);

            // Create Users for each employee
            $users = [];
            $loginChecks = [];

            foreach ($employees as $employee) {
                $salt = Str::uuid()->toString();
                
                $users[] = [
                    'UserID' => $employee['EmployeeID'],
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'IsAdministrator' => 0,
                    'FullName' => $this->generateName($employee['EmployeeID']),
                    'Email' => 'employee' . $employee['EmployeeID'] . '@company.com',
                    'Password' => Hash::make('password123' . $salt),
                    'UTCCode' => '+07:00',
                ];

                $loginChecks[] = [
                    'UserID' => $employee['EmployeeID'],
                    'UserStatusCode' => '11', // Active
                    'IsChangePassword' => 1,
                    'Salt' => $salt,
                    'LastLoginTimeStamp' => null,
                    'LastLoginLocationJSON' => null,
                    'LastLoginAttemptCounter' => 0,
                ];
            }

            DB::table('User')->insert($users);
            DB::table('LoginCheck')->insert($loginChecks);
        });
    }

    private function generateName($employeeId)
    {
        $names = [
            1000001 => 'John Anderson',
            1000002 => 'Sarah Mitchell',
            1000003 => 'Michael Chen',
            1000004 => 'Emma Wilson',
            1000005 => 'David Rodriguez',
            1000006 => 'Robert Thompson',
            1000007 => 'Jessica Parker',
            1000008 => 'Kevin Brown',
            1000009 => 'James Taylor',
            1000010 => 'Lisa Martinez',
            1000011 => 'Christopher Lee',
            1000012 => 'Amanda White',
            1000013 => 'Patricia Harris',
            1000014 => 'Daniel Clark',
            1000015 => 'Jennifer Lewis',
            1000016 => 'Matthew Walker',
        ];

        return $names[$employeeId] ?? 'Employee ' . $employeeId;
    }
}
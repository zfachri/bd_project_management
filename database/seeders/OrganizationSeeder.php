<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $timestamp = Carbon::now()->timestamp;

        DB::transaction(function () use ($timestamp) {
            $userId1 = DB::table('Organization')->insert([
                    'OrganizationID' => 100000000010001,
                    'AtTimeStamp'=> Carbon::now()->timestamp,
                    'ByUserID'=> 1000000,
                    'OperationCode'=> "I",
                    'ParentOrganizationID'=>100000000010001,
                    'LevelNo'=>1,
                    'IsChild'=>false,
                    'OrganizationName'=>"BUSINESS DEVELOPMENT",
                    'IsActive'=>true,
                    'IsDelete'=>false,
            ]);

            // User 2: Regular User - New
            $salt2 = Str::uuid()->toString();
            $userId2 = DB::table('User')->insert([
                'UserID' => 1000001,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => 1,
                'OperationCode' => 'I',
                'IsAdministrator' => false,
                'FullName' => 'John Doe',
                'Email' => 'john.doe@example.com',
                'Password' => Hash::make('123456'.$salt2),
                'UTCCode' => '+07:00',
            ]);

            DB::table('LoginCheck')->insert([
                'UserID' => 1000001,
                'UserStatusCode' => '11', // New
                'IsChangePassword' => true,
                'Salt' => $salt2,
                'LastLoginTimeStamp' => null,
                'LastLoginLocationJSON' => null,
                'LastLoginAttemptCounter' => 0,
            ]);

            // User 3: Suspended
            $salt3 = Str::uuid()->toString();
            $userId3 = DB::table('User')->insert([
                'UserID' => 1000002,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => 1,
                'OperationCode' => 'I',
                'IsAdministrator' => false,
                'FullName' => 'Jane Smith',
                'Email' => 'jane.smith@example.com',
                'Password' => Hash::make('123456'.$salt3),
                'UTCCode' => '+07:00',
            ]);

            DB::table('LoginCheck')->insert([
                'UserID' => 1000002,
                'UserStatusCode' => '10', // Suspended
                'IsChangePassword' => false,
                'Salt' => Str::uuid()->toString(),
                'LastLoginTimeStamp' => $timestamp - 86400,
                'LastLoginLocationJSON' => json_encode([
                    'Longitude' => '106.8456',
                    'Latitude' => '-6.2088'
                ]),
                'LastLoginAttemptCounter' => 0,
            ]);

            // User 4: Blocked
            $salt4 = Str::uuid()->toString();
            $userId4 = DB::table('User')->insert([
                'UserID' => 1000003,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => 1,
                'OperationCode' => 'I',
                'IsAdministrator' => false,
                'FullName' => 'Bob Wilson',
                'Email' => 'bob.wilson@example.com',
                'Password' => Hash::make('123456'.$salt4),
                'UTCCode' => '+07:00',
            ]);

            DB::table('LoginCheck')->insert([
                'UserID' => 1000003,
                'UserStatusCode' => '00', // Blocked
                'IsChangePassword' => false,
                'Salt' => Str::uuid()->toString(),
                'LastLoginTimeStamp' => $timestamp - 172800,
                'LastLoginLocationJSON' => json_encode([
                    'Longitude' => '106.8456',
                    'Latitude' => '-6.2088'
                ]),
                'LastLoginAttemptCounter' => 5,
            ]);
        });
    }
}

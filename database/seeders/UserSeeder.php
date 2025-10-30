<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $timestamp = Carbon::now()->timestamp;

        // User 1: Admin - Active
        $userId1 = DB::table('User')->insertGetId([
            'UserID' => 1000000,
            'AtTimeStamp' => $timestamp,
            'ByUserID' => 1,
            'OperationCode' => 'I',
            'IsAdministrator' => true,
            'FullName' => 'Administrator',
            'Email' => 'admin@example.com',
            'Password' => Hash::make('password123'),
            'UTCCode' => '+07:00',
        ]);

        DB::table('LoginCheck')->insert([
            'UserID' => $userId1,
            'UserStatusCode' => '99', // Active
            'IsChangePassword' => false,
            'Salt' => Str::uuid()->toString(),
            'LastLoginTimeStamp' => $timestamp,
            'LastLoginLocationJSON' => json_encode(['Longitude' => '106.8456', 'Latitude' => '-6.2088']),
            'LastLoginAttemptCounter' => 0,
        ]);

        // User 2: Regular User - New
        $userId2 = DB::table('User')->insertGetId([
            'UserID' => 1000001,
            'AtTimeStamp' => $timestamp,
            'ByUserID' => 1,
            'OperationCode' => 'I',
            'IsAdministrator' => false,
            'FullName' => 'John Doe',
            'Email' => 'john.doe@example.com',
            'Password' => Hash::make('password123'),
            'UTCCode' => '+07:00',
        ]);

        DB::table('LoginCheck')->insert([
            'UserID' => $userId2,
            'UserStatusCode' => '11', // New
            'IsChangePassword' => true,
            'Salt' => Str::uuid()->toString(),
            'LastLoginTimeStamp' => null,
            'LastLoginLocationJSON' => null,
            'LastLoginAttemptCounter' => 0,
        ]);

        // User 3: Regular User - Suspended
        $userId3 = DB::table('User')->insertGetId([
            'UserID' => 1000003,
            'AtTimeStamp' => $timestamp,
            'ByUserID' => 1,
            'OperationCode' => 'I',
            'IsAdministrator' => false,
            'FullName' => 'Jane Smith',
            'Email' => 'jane.smith@example.com',
            'Password' => Hash::make('password123'),
            'UTCCode' => '+07:00',
        ]);

        DB::table('LoginCheck')->insert([
            'UserID' => $userId3,
            'UserStatusCode' => '10', // Suspended
            'IsChangePassword' => false,
            'Salt' => Str::uuid()->toString(),
            'LastLoginTimeStamp' => $timestamp - 86400,
            'LastLoginLocationJSON' => json_encode(['Longitude' => '106.8456', 'Latitude' => '-6.2088']),
            'LastLoginAttemptCounter' => 0,
        ]);

        // User 4: Regular User - Blocked
        $userId4 = DB::table('User')->insertGetId([
            'UserID' => 1000004,
            'AtTimeStamp' => $timestamp,
            'ByUserID' => 1,
            'OperationCode' => 'I',
            'IsAdministrator' => false,
            'FullName' => 'Bob Wilson',
            'Email' => 'bob.wilson@example.com',
            'Password' => Hash::make('password123'),
            'UTCCode' => '+07:00',
        ]);

        DB::table('LoginCheck')->insert([
            'UserID' => $userId4,
            'UserStatusCode' => '00', // Blocked
            'IsChangePassword' => false,
            'Salt' => Str::uuid()->toString(),
            'LastLoginTimeStamp' => $timestamp - 172800,
            'LastLoginLocationJSON' => json_encode(['Longitude' => '106.8456', 'Latitude' => '-6.2088']),
            'LastLoginAttemptCounter' => 5,
        ]);
    }
}

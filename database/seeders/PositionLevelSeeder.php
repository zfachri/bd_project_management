<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PositionLevelSeeder extends Seeder
{
    public function run(): void
    {
        $timestamp = Carbon::now()->timestamp;

        DB::transaction(function () use ($timestamp) {
            $userId1 = DB::table('PositionLevel')->insert([
                [
                    'PositionLevelID' => Carbon::now()->timestamp.random_numbersu(5),
                    'AtTimeStamp' => Carbon::now()->timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'PositionLevelName' => 'GENERAL MANAGER',
                ],
                                [
                    'PositionLevelID' => Carbon::now()->timestamp.random_numbersu(5),
                    'AtTimeStamp' => Carbon::now()->timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'PositionLevelName' => 'JUNIOR MANAGER',
                ],
                                                [
                    'PositionLevelID' => Carbon::now()->timestamp.random_numbersu(5),
                    'AtTimeStamp' => Carbon::now()->timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'PositionLevelName' => 'MANAGER',
                ],
                                                                [
                    'PositionLevelID' => Carbon::now()->timestamp.random_numbersu(5),
                    'AtTimeStamp' => Carbon::now()->timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'PositionLevelName' => 'SENIOR MANAGER',
                ],
                                                                                [
                    'PositionLevelID' => Carbon::now()->timestamp.random_numbersu(5),
                    'AtTimeStamp' => Carbon::now()->timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'PositionLevelName' => 'STAFF',
                ],
                                                                                                [
                    'PositionLevelID' => Carbon::now()->timestamp.random_numbersu(5),
                    'AtTimeStamp' => Carbon::now()->timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'PositionLevelName' => 'SUPERVISOR',
                ]
            ]);
        });
    }
}

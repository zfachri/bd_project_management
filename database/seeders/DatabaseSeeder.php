<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call([
            // UserSeeder::class,
            // SystemReferenceSeeder::class,
            // OrganizationSeeder::class,
            // PositionLevelSeeder::class,
            // 3. Position data (depends on: Organization, PositionLevel)
            PositionSeeder::class,

            // 4. Job Description data (depends on: Position, Organization)
            JobDescriptionSeeder::class,

            // 5. Employee data with User and LoginCheck (depends on: Organization)
            EmployeeSeeder::class,

            // 6. Employee Position data (depends on: Employee, Position, Organization)
            EmployeePositionSeeder::class,
        ]);

        $this->command->info('✅ All seeders completed successfully!');
        $this->command->info('');
        $this->command->info('📊 Summary:');
        $this->command->info('   - Organizations: ' . \DB::table('Organization')->count());
        $this->command->info('   - Position Levels: ' . \DB::table('PositionLevel')->count());
        $this->command->info('   - Positions: ' . \DB::table('Position')->count());
        $this->command->info('   - Job Descriptions: ' . \DB::table('JobDescription')->count());
        $this->command->info('   - Employees: ' . \DB::table('Employee')->count());
        $this->command->info('   - Users: ' . \DB::table('User')->count());
        $this->command->info('   - Employee Positions: ' . \DB::table('EmployeePosition')->count());
        $this->command->info('');
        $this->command->info('🔐 Test Credentials:');
        $this->command->info('   Email: employee1000001@company.com');
        $this->command->info('   Password: password123');
    }
}

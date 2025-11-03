<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\JWTHelper;
use App\Services\OTPService;
use Carbon\Carbon;

class CleanupExpiredTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tokens:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired refresh tokens and OTPs';

    /**
     * Execute the console command.
     */
    public function handle(OTPService $otpService)
    {
        $this->info('Starting cleanup process...');
        $this->newLine();

        // Cleanup expired refresh tokens
        $this->info('Cleaning up expired refresh tokens...');
        try {
            JWTHelper::cleanExpiredTokens();
            $this->info('✓ Expired refresh tokens cleaned up successfully');
        } catch (\Exception $e) {
            $this->error('✗ Failed to clean refresh tokens: ' . $e->getMessage());
        }

        $this->newLine();

        // Cleanup expired OTPs
        $this->info('Cleaning up expired OTPs...');
        try {
            $result = $otpService->cleanExpiredOTPs();
            $this->info("✓ Deleted {$result['deleted_count']} expired OTPs");
        } catch (\Exception $e) {
            $this->error('✗ Failed to clean OTPs: ' . $e->getMessage());
        }

        $this->newLine();
        $this->info('Cleanup completed at: ' . Carbon::now()->toDateTimeString());

        return Command::SUCCESS;
    }
}
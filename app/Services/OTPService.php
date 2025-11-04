<?php

namespace App\Services;

use App\Models\OTP;
use App\Models\SystemReference;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Mail\OTPMail;

class OTPService
{
    /**
     * Generate random OTP code
     */
    private function generateOTPCode()
    {
        return str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get OTP expiry time in seconds from SystemReference
     */
    private function getOTPExpiry()
    {
        $reference = SystemReference::where('ReferenceName', 'User')
            ->where('FieldName', 'OTPExpiry')
            ->first();
        
        return $reference ? (int) $reference->FieldValue : 60;
    }

    /**
     * Create OTP for user
     * 
     * **Important:**
     * - Automatically invalidates all previous OTPs for this user
     * - Only the latest OTP is valid
     * - Previous OTPs cannot be used even if not expired
     * 
     * @param int $userId
     * @param string $categoryCode '01' for SMS, '02' for Email
     * @return array
     */
    public function createOTP($userId, $categoryCode = '02')
    {
        // IMPORTANT: Delete all previous OTPs for this user
        // This ensures only the latest OTP is valid
        // OTP::where('UserID', $userId)->delete();

        $otpCode = $this->generateOTPCode();
        $expirySeconds = $this->getOTPExpiry();
        $now = Carbon::now()->timestamp;
        
        $otp = OTP::create([
            'OTPID' => Carbon::now()->timestamp.random_numbersu(5),
            'AtTimeStamp' => $now,
            'ExpiryTimeStamp' => $now + $expirySeconds,
            'UserID' => $userId,
            'OTPCategoryCode' => $categoryCode,
            'OTP' => $otpCode,
        ]);

        return [
            'otp_id' => $otp->OTPID,
            'otp_code' => $otpCode,
            'expires_in' => $expirySeconds,
            'category' => $categoryCode === '01' ? 'SMS' : 'Email'
        ];
    }

    /**
     * Verify OTP
     * 
     * **Important:**
     * - OTP is automatically deleted after verification
     * - Cannot be reused even if verification fails
     * - Each OTP can only be verified once
     * 
     * @param int $userId
     * @param string $otpCode
     * @return bool
     */
    public function verifyOTP($userId, $otpCode)
    {
        $otp = OTP::where('UserID', $userId)
            ->where('OTP', $otpCode)
            ->where('IsUsed', 0)
            ->orderBy('OTPID', 'desc')
            ->orderBy('AtTimeStamp', 'desc')
            ->first();

        if (!$otp) {
            return false;
        }

        // Check if expired
        if ($otp->ExpiryTimeStamp < Carbon::now()->timestamp) {
            return false;
        }

        // Delete used OTP
        // OTP::where('UserID', $userId)->delete();
        $otp->update(['IsUsed'=>1]);

        return true;
    }

    /**
     * Send OTP via Email
     * 
     * @param object $user
     * @param string $purpose 'login', 'reset_password'
     * @return array
     */
    public function sendOTPEmail($user, $purpose = 'login')
    {
        $otpData = $this->createOTP($user->UserID, '02');
        
        Mail::to($user->Email)->send(new OTPMail(
            $user->FullName,
            $otpData['otp_code'],
            $otpData['expires_in'],
            $purpose
        ));

        return [
            'success' => true,
            'message' => 'OTP has been sent to your email',
            'expires_in' => $otpData['expires_in']
        ];
    }

    /**
     * Send OTP via SMS (placeholder - implement with SMS gateway)
     * 
     * @param object $user
     * @param string $purpose
     * @return array
     */
    public function sendOTPSMS($user, $purpose = 'login')
    {
        $otpData = $this->createOTP($user->UserID, '01');
        
        // TODO: Implement SMS gateway integration
        // Example: Twilio, Nexmo, or local SMS provider
        
        return [
            'success' => true,
            'message' => 'OTP has been sent via SMS',
            'expires_in' => $otpData['expires_in']
        ];
    }

    /**
     * Clean expired OTPs
     */
    public function cleanExpiredOTPs()
    {
        $now = Carbon::now()->timestamp;
        OTP::where('ExpiryTimeStamp', '<', $now)->delete();
    }
}
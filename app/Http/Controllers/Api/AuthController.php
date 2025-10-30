<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\LoginLog;
use App\Models\LoginCheck;
use App\Models\AuditLog;
use App\Services\OTPService;
use App\Helpers\JWTHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AuthController extends Controller
{
    protected $otpService;

    public function __construct(OTPService $otpService)
    {
        $this->otpService = $otpService;
    }

    /**
     * Login
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('Email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        $loginCheck = $user->loginCheck;

        // Check password
        if (!Hash::check($request->password.$loginCheck->Salt, $user->Password)) {
            // Increment login attempt counter
            $this->incrementLoginAttempt($user->UserID);
            
            // Log failed login
            $this->logLogin($user->UserID, false, $request);
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        // Check if user is blocked
        if ($loginCheck->UserStatusCode === '00') {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been blocked. Please contact administrator.'
            ], 403);
        }

        // Check if user is suspended
        if ($loginCheck->UserStatusCode === '10') {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended. Please contact administrator.'
            ], 403);
        }

        // If user status is NEW, send OTP
        if ($loginCheck->UserStatusCode === '11') {
            // Check if request wants SMS
            $sendViaSMS = $request->send_via === 'sms';
            
            if ($sendViaSMS) {
                $otpResult = $this->otpService->sendOTPSMS($user, 'login');
            } else {
                $otpResult = $this->otpService->sendOTPEmail($user, 'login');
            }

            return response()->json([
                'success' => true,
                'message' => 'OTP sent successfully',
                'data' => [
                    'user_id' => $user->UserID,
                    'status' => 'otp_required',
                    'otp_sent_via' => $sendViaSMS ? 'sms' : 'email',
                    'expires_in' => $otpResult['expires_in']
                ]
            ], 200);
        }

        // Reset login attempt counter
        $loginCheck->update([
            'LastLoginAttemptCounter' => 0
        ]);

        // Log successful login
        $this->logLogin($user->UserID, true, $request);

        // Update last login timestamp
        $this->updateLastLogin($user->UserID, $request);

        // Generate tokens
        $accessToken = JWTHelper::generateAccessToken(
            $user->UserID, 
            $user->Email, 
            $user->IsAdministrator
        );
        $refreshToken = JWTHelper::generateRefreshToken($user->UserID, $user->Email);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'UserID' => $user->UserID,
                    'FullName' => $user->FullName,
                    'Email' => $user->Email,
                    'IsAdministrator' => $user->IsAdministrator,
                    'UTCCode'=> $user->UTCCode
                ],
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => config('jwt.access_token_expire', 3600)
            ]
        ], 200);
    }

    /**
     * Verify OTP after login
     */
    public function verifyOTP(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'otp_code' => 'required|string|size:4',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::find($request->user_id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Verify OTP
        if (!$this->otpService->verifyOTP($request->user_id, $request->otp_code)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP code'
            ], 401);
        }

        // Update user status to Active
        $user->loginCheck->update([
            'UserStatusCode' => '99',
            'LastLoginAttemptCounter' => 0
        ]);

        // Log successful login
        $this->logLogin($user->UserID, true, $request);

        // Update last login timestamp
        $this->updateLastLogin($user->UserID, $request);

        // Generate tokens
        $accessToken = JWTHelper::generateAccessToken(
            $user->UserID, 
            $user->Email, 
            $user->IsAdministrator
        );
        $refreshToken = JWTHelper::generateRefreshToken($user->UserID, $user->Email);

        return response()->json([
            'success' => true,
            'message' => 'OTP verified successfully',
            'data' => [
                'user' => [
                    'id' => $user->UserID,
                    'full_name' => $user->FullName,
                    'email' => $user->Email,
                    'is_administrator' => $user->IsAdministrator,
                ],
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => config('jwt.access_token_expire', 3600)
            ]
        ], 200);
    }

    /**
     * Forgot Password - Request OTP
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string', // UserID or Email
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Find user by UserID or Email
        $user = User::where('UserID', $request->identifier)
            ->orWhere('Email', $request->identifier)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Check if request wants SMS
        $sendViaSMS = $request->send_via === 'sms';
        
        if ($sendViaSMS) {
            $otpResult = $this->otpService->sendOTPSMS($user, 'reset_password');
        } else {
            $otpResult = $this->otpService->sendOTPEmail($user, 'reset_password');
        }

        // Create audit log
        AuditLog::create([
            'AtTimeStamp' => Carbon::now()->timestamp,
            'ByUserID' => $user->UserID,
            'OperationCode' => 'U',
            'ReferenceTable' => 'user',
            'ReferenceRecordID' => $user->UserID,
            'Data' => json_encode(['action' => 'forgot_password_request']),
            'Note' => 'User requested password reset'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully',
            'data' => [
                'user_id' => $user->UserID,
                'otp_sent_via' => $sendViaSMS ? 'sms' : 'email',
                'expires_in' => $otpResult['expires_in']
            ]
        ], 200);
    }

    /**
     * Reset Password with OTP
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'otp_code' => 'required|string|size:4',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::find($request->user_id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Verify OTP
        if (!$this->otpService->verifyOTP($request->user_id, $request->otp_code)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP code'
            ], 401);
        }

        // Update password
        $user->update([
            'Password' => Hash::make($request->new_password),
            'AtTimeStamp' => Carbon::now()->timestamp,
            'ByUserID' => $user->UserID,
            'OperationCode' => 'U'
        ]);

        // Update login check - reset IsChangePassword flag
        $user->loginCheck->update([
            'IsChangePassword' => false,
            'Salt' => Str::uuid()->toString()
        ]);

        // Create audit log
        AuditLog::create([
            'AtTimeStamp' => Carbon::now()->timestamp,
            'ByUserID' => $user->UserID,
            'OperationCode' => 'U',
            'ReferenceTable' => 'user',
            'ReferenceRecordID' => $user->UserID,
            'Data' => json_encode(['action' => 'password_reset']),
            'Note' => 'User password has been reset'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully'
        ], 200);
    }

    /**
     * Refresh Token
     */
    public function refreshToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $decoded = JWTHelper::verifyToken($request->refresh_token);

            // Check if token type is refresh
            if ($decoded['type'] !== 'refresh') {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid token type'
                ], 401);
            }

            $userId = $decoded['sub'];
            $user = User::find($userId);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Generate new tokens
            $accessToken = JWTHelper::generateAccessToken(
                $user->UserID, 
                $user->Email, 
                $user->IsAdministrator
            );
            $refreshToken = JWTHelper::generateRefreshToken($user->UserID, $user->Email);

            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'data' => [
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'token_type' => 'Bearer',
                    'expires_in' => config('jwt.access_token_expire', 3600)
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired refresh token'
            ], 401);
        }
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        try {
            $token = $request->bearerToken();
            
            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token not provided'
                ], 401);
            }

            $userId = JWTHelper::getUserIdFromToken($token);

            // Create audit log
            AuditLog::create([
                'AtTimeStamp' => Carbon::now()->timestamp,
                'ByUserID' => $userId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'user',
                'ReferenceRecordID' => $userId,
                'Data' => json_encode(['action' => 'logout']),
                'Note' => 'User logged out'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Logout successful'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid token'
            ], 401);
        }
    }

    /**
     * Helper: Log login attempt
     */
    private function logLogin($userId, $isSuccessful, $request)
    {
        LoginLog::create([
            'UserID' => $userId,
            'IsSuccessful' => $isSuccessful,
            'LoginTimeStamp' => Carbon::now()->timestamp,
            'LoginLocationJSON' => json_encode([
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ])
        ]);
    }

    /**
     * Helper: Update last login info
     */
    private function updateLastLogin($userId, $request)
    {
        LoginCheck::where('UserID', $userId)->update([
            'LastLoginTimeStamp' => Carbon::now()->timestamp,
            'LastLoginLocationJSON' => json_encode([
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ])
        ]);
    }

    /**
     * Helper: Increment login attempt counter
     */
    private function incrementLoginAttempt($userId)
    {
        $loginCheck = LoginCheck::where('UserID', $userId)->first();
        $counter = $loginCheck->LastLoginAttemptCounter + 1;
        
        // Get max attempt from system reference
        $maxAttempt = 5; // Default
        
        // If max attempt reached, block user
        if ($counter >= $maxAttempt) {
            $loginCheck->update([
                'UserStatusCode' => '00', // Blocked
                'LastLoginAttemptCounter' => $counter
            ]);
        } else {
            $loginCheck->update([
                'LastLoginAttemptCounter' => $counter
            ]);
        }
    }
}
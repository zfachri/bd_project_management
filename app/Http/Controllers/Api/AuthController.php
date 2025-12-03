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

/**
 * @tags Authentication
 */
class AuthController extends Controller
{
    protected $otpService;

    public function __construct(OTPService $otpService)
    {
        $this->otpService = $otpService;
    }

    /**
     * User Login
     * 
     * Authenticate user with email/UserID and password.
     * Returns JWT access token and refresh token on successful authentication.
     * 
     * **Login Flow:**
     * - Active User (Status 99): Returns tokens immediately
     * - New User (Status 11): Sends OTP to email/SMS
     * - Blocked User (Status 00): Returns error 403
     * - Suspended User (Status 10): Returns error 403
     * 
     * @unauthenticated
     */
    public function login(Request $request)
    {
        $loginField = $request->input('Email');
        $loginType = filter_var($loginField, FILTER_VALIDATE_EMAIL) ? 'Email' : 'UserID';
        $validator = Validator::make($request->all(), [
            'Email' => 'required|string',
            'Password' => 'required|string|min:6|max:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where($loginType, $request->Email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        $loginCheck = $user->loginCheck;

        // Check password
        if (!Hash::check($request->Password.$loginCheck->Salt, $user->Password)) {
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
                    'UserID' => (string) $user->UserID,
                    'Status' => 'otp_required',
                    'OTPSentVia' => $sendViaSMS ? 'sms' : 'email',
                    'ExpiresIn' => $otpResult['expires_in'],
                    'RequiresPasswordChange' => $loginCheck->IsChangePassword
                ]
            ], 200);
        }

        // Check if user must change password
        if ($loginCheck->IsChangePassword) {
            return response()->json([
                'success' => true,
                'message' => 'Password change required',
                'data' => [
                    'UserID' => (string) $user->UserID,
                    'Status' => 'password_change_required',
                    'RequiresPasswordChange' => true
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
                    'UserID' => (string) $user->UserID,
                    'FullName' => $user->FullName,
                    'Email' => $user->Email,
                    'IsAdministrator' => $user->IsAdministrator,
                    'UTCCode' => $user->UTCCode
                ],
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => config('jwt.access_token_expire', 3600)
            ]
        ], 200);
    }

    /**
     * Verify OTP
     * 
     * Verify OTP code sent to user's email or phone.
     * After successful verification, if IsChangePassword=1, user must change password.
     * Otherwise, returns JWT tokens.
     * 
     * @unauthenticated
     */
    public function verifyOTP(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'UserID' => 'required|string',
            'OTPCode' => 'required|string|size:4',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::find($request->UserID);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Verify OTP
        if (!$this->otpService->verifyOTP($request->UserID, $request->OTPCode)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP code'
            ], 401);
        }

        $loginCheck = $user->loginCheck;

        // Update user status to Active and reset counter
        $loginCheck->update([
            'UserStatusCode' => '99',
            'LastLoginAttemptCounter' => 0
        ]);

        // Check if user must change password after OTP verification
        if ($loginCheck->IsChangePassword) {
            return response()->json([
                'success' => true,
                'message' => 'OTP verified. Password change required.',
                'data' => [
                    'UserID' => (string) $user->UserID,
                    'Status' => 'password_change_required',
                    'RequiresPasswordChange' => true
                ]
            ], 200);
        }

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
                    'UserID' => $user->UserID,
                    'FullName' => $user->FullName,
                    'Email' => $user->Email,
                    'IsAdministrator' => $user->IsAdministrator,
                    'UTCCode' => $user->UTCCode
                ],
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => config('jwt.access_token_expire', 3600)
            ]
        ], 200);
    }

    /**
     * Change Password (Authenticated)
     * 
     * Change user password (requires authentication).
     * User must be logged in and can only change their own password.
     * Requires old password verification.
     * 
     * **Use Cases:**
     * - User wants to change password while logged in
     * - After first login (IsChangePassword=1)
     * - Regular password update
     * 
     * **Note:** For forgot password scenario, use `/auth/reset-password` endpoint instead.
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'OldPassword' => 'required|string|size:6',
            'NewPassword' => 'required|string|size:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Get authenticated user from JWT token (injected by middleware)
        $authUserId = $request->auth_user_id;
        $user = User::find($authUserId);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $userCheck = $user->loginCheck;

        // Verify old password
        if (!Hash::check($request->OldPassword . $userCheck->Salt, $user->Password)) {
            return response()->json([
                'success' => false,
                'message' => 'Old password is incorrect'
            ], 401);
        }

        // Check if new password is same as old password
        if ($request->OldPassword === $request->NewPassword) {
            return response()->json([
                'success' => false,
                'message' => 'New password must be different from old password'
            ], 422);
        }

        // Generate new salt for password change
        $newSalt = Str::uuid()->toString();

        // Update password with new salt
        $timestamp = Carbon::now()->timestamp;
        $user->update([
            'Password' => Hash::make($request->NewPassword . $newSalt),
            'AtTimeStamp' => $timestamp,
            'ByUserID' => $user->UserID,
            'OperationCode' => 'U'
        ]);

        // Update login check - set IsChangePassword to false and update salt
        $user->loginCheck->update([
            'IsChangePassword' => false,
            'Salt' => $newSalt
        ]);

        // Create audit log
        AuditLog::create([
            'AuditLogID'=>Carbon::now()->timestamp.random_numbersu(5),
            'AtTimeStamp' => $timestamp,
            'ByUserID' => $user->UserID,
            'OperationCode' => 'U',
            'ReferenceTable' => 'user',
            'ReferenceRecordID' => $user->UserID,
            'Data' => json_encode(['action' => 'password_changed']),
            'Note' => 'User changed password'
        ]);

        // Generate new tokens with updated credentials
        $accessToken = JWTHelper::generateAccessToken(
            $user->UserID,
            $user->Email,
            $user->IsAdministrator
        );
        $refreshToken = JWTHelper::generateRefreshToken($user->UserID, $user->Email);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully',
            'data' => [
                'user' => [
                    'UserID' => $user->UserID,
                    'FullName' => $user->FullName,
                    'Email' => $user->Email,
                    'IsAdministrator' => $user->IsAdministrator,
                    'UTCCode' => $user->UTCCode
                ],
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => config('jwt.access_token_expire', 3600)
            ]
        ], 200);
    }

    /**
     * Forgot Password
     * 
     * Request OTP for password reset.
     * User can provide UserID or Email as identifier.
     * If you are using UserID you can still need to sent the body using "Email" property.
     * OTP will be sent via email by default, or SMS if specified.
     * 
     * @unauthenticated
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // UserID or Email
            'Email' => 'required|string',
            'SendVia' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Find user by UserID or Email
        $user = User::where('UserID', $request->Email)
            ->orWhere('Email', $request->Email)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Check if request wants SMS
        $sendViaSMS = $request->SendVia === 'sms';

        if ($sendViaSMS) {
            $otpResult = $this->otpService->sendOTPSMS($user, 'reset_password');
        } else {
            $otpResult = $this->otpService->sendOTPEmail($user, 'reset_password');
        }

        // Create audit log
        AuditLog::create([
            'AuditLogID'=>Carbon::now()->timestamp.random_numbersu(5),
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
                'UserID' => $user->UserID,
                'OTPSentVia' => $sendViaSMS ? 'sms' : 'email',
                'Expires_In' => $otpResult['expires_in']
            ]
        ], 200);
    }

    /**
     * Reset Password
     * 
     * Reset password using OTP code.
     * After successful reset, user can login with new password.
     * 
     * @unauthenticated
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'UserID' => 'required|string',
            'OTPCode' => 'required|string|size:4',
            'NewPassword' => 'required|string|size:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('UserID', $request->UserID)
            ->orWhere('Email', $request->UserID)
            ->first();

        // $user = User::find($request->user_id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Verify OTP
        if (!$this->otpService->verifyOTP($request->UserID, $request->OTPCode)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP code'
            ], 401);
        }

        // Update password
        $timestamp = Carbon::now()->timestamp;
        $salt = Str::uuid()->toString();
        $user->update([
            'Password' => Hash::make($request->NewPassword . $salt),
            'AtTimeStamp' => $timestamp,
            'ByUserID' => $user->UserID,
            'OperationCode' => 'U'
        ]);

        // Update login check - reset IsChangePassword flag
        $user->loginCheck->update([
            'IsChangePassword' => false,
            'Salt' => $salt
        ]);

        // Create audit log
        AuditLog::create([
            'AuditLogID'=>Carbon::now()->timestamp.random_numbersu(5),
            'AtTimeStamp' => $timestamp,
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
     * 
     * Refresh JWT access token using refresh token.
     * Returns new access token and refresh token.
     * 
     * **Important:**
     * - Each refresh token can only be used once
     * - After use, the old refresh token becomes invalid
     * - Even if the old token hasn't expired, it cannot be reused
     * - This prevents token replay attacks
     * 
     * @unauthenticated
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
            // Verify and validate refresh token
            $tokenData = JWTHelper::verifyRefreshToken($request->refresh_token);

            $userId = $tokenData['user_id'];
            $user = User::find($userId);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Check if user is active
            if (!$user->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User account is not active'
                ], 403);
            }

            // Mark old refresh token as used (one-time use)
            JWTHelper::markRefreshTokenAsUsed($request->refresh_token);

            // Generate new tokens
            $accessToken = JWTHelper::generateAccessToken(
                $user->UserID,
                $user->Email,
                $user->IsAdministrator
            );
            $refreshToken = JWTHelper::generateRefreshToken($user->UserID, $user->Email);

            // Create audit log
            AuditLog::create([
                'AuditLogID'=>Carbon::now()->timestamp.random_numbersu(5),
                'AtTimeStamp' => Carbon::now()->timestamp,
                'ByUserID' => $userId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'user',
                'ReferenceRecordID' => $userId,
                'Data' => json_encode(['action' => 'token_refreshed']),
                'Note' => 'User refreshed access token'
            ]);

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
                'message' => $e->getMessage()
            ], 401);
        }
    }

    /**
     * Logout
     * 
     * Logout user and invalidate tokens.
     * Creates audit log entry for logout action.
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

            // Revoke all refresh tokens for this user
            JWTHelper::revokeAllRefreshTokens($userId);

            // Create audit log
            AuditLog::create([
                'AuditLogID'=>Carbon::now()->timestamp.random_numbersu(5),
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
            'LoginLogID' => Carbon::now()->timestamp.random_numbersu(5),
            'UserID' => $userId,
            'IsSuccessful' => $isSuccessful,
            'LoginTimeStamp' => Carbon::now()->timestamp,
            'LoginLocationJSON' => json_encode([
                'IP' => $request->ip(),
                'UserAgent' => $request->userAgent()
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
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
                'IP' => $request->ip(),
                'UserAgent' => $request->userAgent()
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
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

<?php

namespace App\Helpers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Models\RefreshToken;

class JWTHelper
{
    private static function getPrivateKey()
    {
        $key = config('jwt.private_key');
        return base64_decode($key);
    }

    private static function getPublicKey()
    {
        $key = config('jwt.public_key');
        return base64_decode($key);
    }

    /**
     * Generate Access Token
     */
    public static function generateAccessToken($userId, $email, $isAdmin = false)
    {
        $expiresIn = config('jwt.access_token_expire', 3600); // 1 hour default
        $issuedAt = Carbon::now()->timestamp;
        $expiration = $issuedAt + $expiresIn;

        $payload = [
            'iss' => Str::uuid()->toString(),
            'iat' => $issuedAt,
            'exp' => $expiration,
            'sub' => $userId,
            'data' => [
                "Email" => $email,
                "UserID" => $userId,
                "IsAdministrator" => $isAdmin
            ],
            'type' => 'access'
        ];

        return JWT::encode($payload, self::getPrivateKey(), 'RS256');
    }

    /**
     * Generate Refresh Token
     */
    public static function generateRefreshToken($userId, $email)
    {
        $expiresIn = config('jwt.refresh_token_expire', 604800); // 7 days default
        $issuedAt = Carbon::now()->timestamp;
        $expiration = $issuedAt + $expiresIn;

        $payload = [
            'iss' => config('app.url'),
            'aud' => config('app.url'),
            'iat' => $issuedAt,
            'exp' => $expiration,
            'sub' => $userId,
            'email' => $email,
            'type' => 'refresh'
        ];

        $token = JWT::encode($payload, self::getPrivateKey(), 'RS256');

                // Store token in database
        RefreshToken::create([
            'UserID' => $userId,
            'Token' => hash('sha256', $token), // Store hash for security
            'ExpiresAt' => $expiration,
            'IsUsed' => false,
            'UsedAt' => null,
            'CreatedAt' => $issuedAt,
        ]);

        return $token;
    }

     /**
     * Verify and validate refresh token
     */
    public static function verifyRefreshToken($token)
    {
        try {
            // Decode token
            $decoded = JWT::decode($token, new Key(self::getPublicKey(), 'RS256'));
            $decodedArray = (array) $decoded;

            // Check if token type is refresh
            if ($decodedArray['type'] !== 'refresh') {
                throw new \Exception('Invalid token type');
            }

            // Check if token exists and is valid in database
            $tokenHash = hash('sha256', $token);
            $storedToken = RefreshToken::where('Token', $tokenHash)
                ->where('UserID', $decodedArray['sub'])
                ->first();

            if (!$storedToken) {
                throw new \Exception('Refresh token not found');
            }

            if ($storedToken->IsUsed) {
                throw new \Exception('Refresh token already used');
            }

            if ($storedToken->isExpired()) {
                throw new \Exception('Refresh token expired');
            }

            return [
                'valid' => true,
                'user_id' => $decodedArray['sub'],
                'email' => $decodedArray['email'],
                'token_record' => $storedToken
            ];

        } catch (\Exception $e) {
            throw new \Exception('Invalid refresh token: ' . $e->getMessage());
        }
    }

    /**
     * Mark refresh token as used
     */
    public static function markRefreshTokenAsUsed($token)
    {
        $tokenHash = hash('sha256', $token);
        $storedToken = RefreshToken::where('Token', $tokenHash)->first();
        
        if ($storedToken) {
            $storedToken->markAsUsed();
        }
    }

    /**
     * Revoke all refresh tokens for a user
     */
    public static function revokeAllRefreshTokens($userId)
    {
        RefreshToken::where('UserID', $userId)
            ->where('IsUsed', false)
            ->update([
                'IsUsed' => true,
                'UsedAt' => Carbon::now()->timestamp,
            ]);
    }

    /**
     * Clean expired refresh tokens
     */
    public static function cleanExpiredTokens()
    {
        $now = Carbon::now()->timestamp;
        RefreshToken::where('ExpiresAt', '<', $now)->delete();
    }

    /**
     * Decode and Verify Token
     */
    public static function verifyToken($token)
    {
        try {
            $decoded = JWT::decode($token, new Key(self::getPublicKey(), 'RS256'));
            return (array) $decoded;
        } catch (\Exception $e) {
            throw new \Exception('Invalid token: ' . $e->getMessage());
        }
    }

    /**
     * Check if token needs refresh (3/4 of expiration time)
     */
    public static function needsRefresh($token)
    {
        try {
            $decoded = self::verifyToken($token);
            $now = Carbon::now()->timestamp;
            $exp = $decoded['exp'];
            $iat = $decoded['iat'];
            
            $totalLifetime = $exp - $iat;
            $threeQuarters = $iat + ($totalLifetime * 0.75);
            
            return $now >= $threeQuarters;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get user ID from token
     */
    public static function getUserIdFromToken($token)
    {
        $decoded = self::verifyToken($token);
        return $decoded['sub'] ?? null;
    }

    /**
     * Get token type
     */
    public static function getTokenType($token)
    {
        $decoded = self::verifyToken($token);
        return $decoded['type'] ?? null;
    }
}
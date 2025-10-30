<?php

return [
    /*
    |--------------------------------------------------------------------------
    | JWT Private Key (Base64 encoded)
    |--------------------------------------------------------------------------
    |
    | RSA256 private key for signing JWT tokens
    | Generate key: openssl genrsa -out private.pem 2048
    | Encode to base64: base64 private.pem -w 0
    |
    */
    'private_key' => env('JWT_PRIVATE_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | JWT Public Key (Base64 encoded)
    |--------------------------------------------------------------------------
    |
    | RSA256 public key for verifying JWT tokens
    | Generate key: openssl rsa -in private.pem -pubout -out public.pem
    | Encode to base64: base64 public.pem -w 0
    |
    */
    'public_key' => env('JWT_PUBLIC_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Access Token Expiration (in seconds)
    |--------------------------------------------------------------------------
    |
    | Default: 3600 seconds (1 hour)
    |
    */
    'access_token_expire' => env('JWT_ACCESS_TOKEN_EXPIRE', 3600),

    /*
    |--------------------------------------------------------------------------
    | Refresh Token Expiration (in seconds)
    |--------------------------------------------------------------------------
    |
    | Default: 86400 seconds (1 day)
    |
    */
    'refresh_token_expire' => env('JWT_REFRESH_TOKEN_EXPIRE', 86400),
];
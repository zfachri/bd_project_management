<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\PositionLevelController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\EmployeeController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes (no authentication required)
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOTP']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
});

// Protected routes (authentication required)
Route::middleware(['jwt.auth'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::get('/{id}', [UserController::class, 'show']);
        Route::post('/', [UserController::class, 'store']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::patch('/{id}/toggle-block', [UserController::class, 'toggleBlock']);
        Route::patch('/{id}/toggle-suspend', [UserController::class, 'toggleSuspend']);
    });

    // Position Level Management
    Route::prefix('position-levels')->group(function () {
        Route::get('/', [PositionLevelController::class, 'index']);
        Route::get('/all', [PositionLevelController::class, 'all']);
        Route::get('/{id}', [PositionLevelController::class, 'show']);
        Route::post('/', [PositionLevelController::class, 'store']);
        Route::put('/{id}', [PositionLevelController::class, 'update']);
        Route::delete('/{id}', [PositionLevelController::class, 'destroy']);
    });

    // Organization Management
    Route::prefix('organizations')->group(function () {
        Route::get('/', [OrganizationController::class, 'index']);
        Route::get('/all', [OrganizationController::class, 'all']);
        Route::get('/level/{level}', [OrganizationController::class, 'getByLevel']);
        Route::get('/{id}', [OrganizationController::class, 'show']);
        Route::get('/{id}/children', [OrganizationController::class, 'getChildren']);
        Route::post('/', [OrganizationController::class, 'store']);
        Route::put('/{id}', [OrganizationController::class, 'update']);
        // Route::delete('/{id}', [OrganizationController::class, 'destroy']);
        Route::patch('/{id}/toggle-active', [OrganizationController::class, 'toggleActive']);

        // Get full hierarchy tree
        Route::get('/hierarchy', [OrganizationController::class, 'getHierarchy']);

        // Get hierarchy from specific organization
        Route::get('/hierarchy/{id}', [OrganizationController::class, 'getHierarchyFrom']);
    });

    // Position Management (with Job Description)
    Route::prefix('positions')->group(function () {
        Route::get('/', [PositionController::class, 'index']);
        Route::get('/all', [PositionController::class, 'all']);
        Route::get('/organization/{organizationId}', [PositionController::class, 'getByOrganization']);
        Route::get('/{id}', [PositionController::class, 'show']);
        Route::get('/{id}/children', [PositionController::class, 'getChildren']);
        Route::post('/', [PositionController::class, 'store']);
        Route::put('/{id}', [PositionController::class, 'update']);
        // Route::delete('/{id}', [PositionController::class, 'destroy']);
        Route::patch('/{id}/toggle-active', [PositionController::class, 'toggleActive']);
    });

    // Employee Management (with Position)
    Route::prefix('employees')->group(function () {
        Route::get('/', [EmployeeController::class, 'index']);
        Route::get('/all', [EmployeeController::class, 'all']);
        Route::get('/{id}', [EmployeeController::class, 'show']);
        Route::post('/', [EmployeeController::class, 'store']);
        Route::put('/{id}', [EmployeeController::class, 'update']);
        Route::delete('/{id}', [EmployeeController::class, 'destroy']);
        Route::patch('/{id}/resign', [EmployeeController::class, 'resign']);

        // Employee Position Management
        Route::post('/{id}/positions', [EmployeeController::class, 'addPosition']);
        Route::put('/{id}/positions/{positionId}', [EmployeeController::class, 'updatePosition']);
        Route::delete('/{id}/positions/{positionId}', [EmployeeController::class, 'removePosition']);
    });
});

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DocumentManagementController;
use App\Http\Controllers\Api\DocumentRevisionController;
use App\Http\Controllers\Api\DocumentSubmissionController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\PositionLevelController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\FileUploadController;
use App\Http\Controllers\Api\RoleController;

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
        Route::middleware('permission:User.view')->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::get('/{id}', [UserController::class, 'show']);
        });

        Route::post('/', [UserController::class, 'store'])->middleware('permission:User.create');

        Route::middleware('permission:User.edit')->group(function () {
            Route::put('/{id}', [UserController::class, 'update']);
            Route::patch('/{id}/toggle-block', [UserController::class, 'toggleBlock']);
            Route::patch('/{id}/toggle-suspend', [UserController::class, 'toggleSuspend']);
        });
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
        Route::middleware('permission:Organization.view')->group(function () {
            Route::get('/', [OrganizationController::class, 'index']);
            Route::get('/all', [OrganizationController::class, 'all']);
            Route::get('/{id}', [OrganizationController::class, 'show']);
            Route::get('/level/{level}', [OrganizationController::class, 'getByLevel']);
            Route::get('/hierarchy', [OrganizationController::class, 'getHierarchy']);
            Route::get('/hierarchy/{id}', [OrganizationController::class, 'getHierarchyFrom']);
            Route::get('/{id}/children', [OrganizationController::class, 'getChildren']);
        });
        Route::post('/', [OrganizationController::class, 'store'])->middleware('permission:Organization.create');
        Route::middleware('permission:Organization.edit')->group(function () {
            Route::put('/{id}', [OrganizationController::class, 'update']);
            Route::patch('/{id}/toggle-active', [OrganizationController::class, 'toggleActive']);
        });
    });

    // Position Management (with Job Description)
    Route::prefix('positions')->group(function () {
        Route::middleware('permission:Position.view')->group(function () {
            Route::get('/', [PositionController::class, 'index']);
            Route::get('/all', [PositionController::class, 'all']);
            Route::get('/{id}', [PositionController::class, 'show']);
            Route::get('/hierarchy', [PositionController::class, 'getHierarchy']);
            Route::get('/{id}/hierarchy', [PositionController::class, 'getPositionHierarchy']);
            Route::get('/organization/{organizationId}', [PositionController::class, 'getByOrganization']);
            Route::get('/{id}/children', [PositionController::class, 'getChildren']);
            Route::get('/{positionId}/employees', [EmployeeController::class, 'getEmployeesByPosition']);
            Route::get('/{positionId}/hierarchy/employees', [EmployeeController::class, 'getPositionHierarchyWithEmployees']);
        });

        Route::post('/', [PositionController::class, 'store'])->middleware('permission:Position.create');
        Route::middleware('permission:Position.edit')->group(function () {
            Route::put('/{id}', [PositionController::class, 'update']);
            Route::patch('/{id}/toggle-active', [PositionController::class, 'toggleActive']);
        });
    });

    // Employee Management (with Position)  
    Route::prefix('employees')->group(function () {
        Route::middleware('permission:Employee.view')->group(function () {
            Route::get('/', [EmployeeController::class, 'index']);
            Route::get('/all', [EmployeeController::class, 'all']);
            Route::get('/{id}', [EmployeeController::class, 'show']);
            Route::get('/{id}/hierarchy', [EmployeeController::class, 'getHierarchy']);
            Route::get('/hierarchy/tree', [EmployeeController::class, 'getOrganizationHierarchyTree']);
        });

        Route::post('/', [EmployeeController::class, 'store'])->middleware('permission:Employee.create');
        // Edit permission
        Route::middleware('permission:Employee.edit')->group(function () {
            Route::put('/{id}', [EmployeeController::class, 'update']);
            Route::patch('/{id}/resign', [EmployeeController::class, 'resign']);
            Route::post('/{id}/positions', [EmployeeController::class, 'addPosition']);
            Route::put('/{id}/positions/{positionId}', [EmployeeController::class, 'updatePosition']);
            Route::delete('/{id}/positions/{positionId}', [EmployeeController::class, 'removePosition']);
        });

        Route::delete('/{id}', [EmployeeController::class, 'destroy'])->middleware('permission:Employee.delete');
    });

    Route::prefix('documents')->group(function () {
        // Generate presigned URL for single file upload
        Route::post('/upload-url', [FileUploadController::class, 'generateUploadUrl']);

        // Generate presigned URLs for batch upload (max 5 files)
        Route::post('/batch-upload-url', [FileUploadController::class, 'generateBatchUploadUrl']);

        // Generate presigned URL for download/view (with force_download parameter)
        Route::post('/download-url', [FileUploadController::class, 'generateDownloadUrl']);

        // Generate presigned URL for viewing inline (shorthand)
        Route::post('/view-url', [FileUploadController::class, 'generateViewUrl']);

        // Get documents by module
        Route::post('/list', [FileUploadController::class, 'getDocumentsByModule']);

        // Update document information
        Route::put('/update', [FileUploadController::class, 'updateDocument']);

        // Soft delete document
        Route::delete('/delete', [FileUploadController::class, 'deleteDocument']);

        // Get allowed file types and size limits
        Route::get('/config', [FileUploadController::class, 'getAllowedFileTypes']);
    });

    Route::prefix('document-management')->group(function () {

        // List documents by organization
        Route::post('/list', [DocumentManagementController::class, 'listByOrganization']);
        Route::middleware('permission:Document.view')->group(function () {
            // Get document details with all versions
            Route::get('/{documentId}', [DocumentManagementController::class, 'getDocument']);
            Route::get('/document/view/{documentId}', [DocumentManagementController::class, 'viewDocument']);
            Route::post('/{documentId}/allVersion', [DocumentManagementController::class, 'getAllVersions']);
        });
        Route::middleware('permission:Document.create')->group(function () {
            Route::post('/create', [DocumentManagementController::class, 'createDocument']);
        });
        Route::middleware('permission:Document.edit')->group(function () {
            Route::post('/{documentId}/upload-version', [DocumentManagementController::class, 'updateDocument']);
            Route::put('/{documentId}/info', [DocumentManagementController::class, 'updateDocumentInfo']);
            Route::post('/{documentId}/add-raci-document', [DocumentManagementController::class, 'addRaciDocument']);
        });
        // Get document version URL (view/download)
        Route::post('/version-url', [DocumentManagementController::class, 'getVersionUrl']);
    });

    Route::prefix('document-submission')->group(function () {
        // User request submission
        Route::post('/request', [DocumentSubmissionController::class, 'requestSubmission']);

        // Get user's own submissions
        Route::get('/my-submissions', [DocumentSubmissionController::class, 'getMySubmissions']);

        // Get single submission detail
        Route::get('/{submissionId}', [DocumentSubmissionController::class, 'getSubmission']);

        Route::middleware('permission:DocumentSubmission.view')->group(function () {
            Route::post('/list', [DocumentSubmissionController::class, 'listSubmissions']);
            Route::get('/pending/list', [DocumentSubmissionController::class, 'getPendingSubmissions']);
            Route::get('/stats/summary', [DocumentSubmissionController::class, 'getSubmissionStats']);
        });

        // Admin functions - require edit permission
        Route::middleware('permission:DocumentSubmission.edit')->group(function () {
            Route::put('/{submissionId}/approve', [DocumentSubmissionController::class, 'approveSubmission']);
            Route::put('/{submissionId}/decline', [DocumentSubmissionController::class, 'declineSubmission']);
        });
    });

    Route::prefix('document-revision')->group(function () {
        Route::post('/document/{documentManagementId}/request', [DocumentRevisionController::class, 'requestRevision']);
        Route::get('/my-revisions', [DocumentRevisionController::class, 'getMyRevisions']);
        Route::get('/{revisionId}', [DocumentRevisionController::class, 'getRevision']);
        Route::middleware('permission:DocumentRevision.view')->group(function () {
            Route::get('/document/{documentManagementId}', [DocumentRevisionController::class, 'getDocumentRevisions']);
            Route::post('/list', [DocumentRevisionController::class, 'listRevisions']);
            Route::get('/pending/list', [DocumentRevisionController::class, 'getPendingRevisions']);
            Route::get('/approved/list', [DocumentRevisionController::class, 'getApprovedRevisions']);
            Route::get('/stats/summary', [DocumentRevisionController::class, 'getRevisionStats']);
        });
        Route::middleware('permission:DocumentRevision.edit')->group(function () {
            Route::put('/{revisionId}/approve', [DocumentRevisionController::class, 'approveRevision']);
            Route::put('/{revisionId}/decline', [DocumentRevisionController::class, 'declineRevision']);
        });
    });

    // ========================================
    // ROLE & PERMISSION MANAGEMENT
    // ========================================

    Route::prefix('roles')->group(function () {
        // Get all roles with pagination
        Route::get('/', [RoleController::class, 'index']);

        // Get all roles without pagination
        Route::get('/all', [RoleController::class, 'all']);

        // Get all modules (for permission setup UI)
        Route::get('/modules', [RoleController::class, 'getModules']);

        // Get single role with permissions
        Route::get('/{id}', [RoleController::class, 'show']);

        // Get employee's current role and permissions
        Route::get('/employee/{employeeId}', [RoleController::class, 'getEmployeeRole']);

        // Create new role
        Route::post('/', [RoleController::class, 'store'])
            ->middleware('permission:Role.create');

        // Update role (name, description, status)
        Route::put('/{id}', [RoleController::class, 'update'])
            ->middleware('permission:Role.edit');

        // Update role permissions
        Route::put('/{id}/permissions', [RoleController::class, 'updatePermissions'])
            ->middleware('permission:Role.edit');

        // Delete role (soft delete)
        Route::delete('/{id}', [RoleController::class, 'destroy'])
            ->middleware('permission:Role.delete');

        // Assign role to employee
        Route::post('/assign', [RoleController::class, 'assignToEmployee'])
            ->middleware('permission:Role.edit');

        // Remove role from employee
        Route::delete('/unassign/{employeeId}', [RoleController::class, 'unassignFromEmployee'])
            ->middleware('permission:Role.edit');
    });
});

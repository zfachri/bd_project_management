<?php

/**
 * Migration 1: Create Roles Table
 * File: database/migrations/2024_12_09_000001_create_roles_table.php
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('Role', function (Blueprint $table) {
            $table->bigInteger('RoleID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->integer('ByUserID');
            $table->char('OperationCode', 1); // I, U, D
            
            $table->string('RoleName', 100)->unique();
            $table->string('Description', 500)->nullable();
            $table->boolean('IsActive')->default(true);
            $table->boolean('IsDelete')->default(false);
            
            $table->index('RoleName');
            $table->index('IsActive');
        });
    }

    public function down()
    {
        Schema::dropIfExists('Role');
    }
};

/**
 * Migration 2: Create Modules Table
 * File: database/migrations/2024_12_09_000002_create_modules_table.php
 */


return new class extends Migration
{
    public function up()
    {
        Schema::create('Module', function (Blueprint $table) {
            $table->bigInteger('ModuleID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->integer('ByUserID');
            $table->char('OperationCode', 1);
            
            $table->string('ModuleName', 100)->unique(); // e.g., "JobDescription", "Project"
            $table->string('DisplayName', 100); // e.g., "Job Description", "Project Management"
            $table->string('Description', 500)->nullable();
            $table->integer('SortOrder')->default(0);
            $table->boolean('IsActive')->default(true);
            $table->boolean('IsDelete')->default(false);
            
            $table->index('ModuleName');
            $table->index('IsActive');
        });
    }

    public function down()
    {
        Schema::dropIfExists('Module');
    }
};

/**
 * Migration 3: Create Permissions Table
 * File: database/migrations/2024_12_09_000003_create_permissions_table.php
 */

return new class extends Migration
{
    public function up()
    {
        Schema::create('Permission', function (Blueprint $table) {
            $table->bigInteger('PermissionID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->integer('ByUserID');
            $table->char('OperationCode', 1);
            
            $table->bigInteger('RoleID');
            $table->bigInteger('ModuleID');
            
            // Basic CRUD Permissions
            $table->boolean('CanCreate')->default(false);
            $table->boolean('CanView')->default(false);
            $table->boolean('CanEdit')->default(false);
            $table->boolean('CanDelete')->default(false);
            
            // Hierarchical Access Permissions
            $table->boolean('CanAccessSubordinates')->default(false); // Akses data bawahan
            $table->boolean('CanAccessParentOrg')->default(false);    // Akses parent organization
            $table->boolean('CanAccessChildOrg')->default(false);     // Akses child organization
            
            // Scope Level: 'own', 'organization', 'position_tree', 'all'
            $table->enum('Scope', ['own', 'organization', 'position_tree', 'all'])->default('own');
            
            $table->boolean('IsDelete')->default(false);
            
            // Foreign keys
            $table->foreign('RoleID')->references('RoleID')->on('Role')->onDelete('cascade');
            $table->foreign('ModuleID')->references('ModuleID')->on('Module')->onDelete('cascade');
            
            // Unique constraint: one role can only have one set of permissions per module
            $table->unique(['RoleID', 'ModuleID'], 'UK_Permission_Role_Module');
            
            $table->index('RoleID');
            $table->index('ModuleID');
        });
    }

    public function down()
    {
        Schema::dropIfExists('Permission');
    }
};

/**
 * Migration 4: Create Employee Roles Table
 * File: database/migrations/2024_12_09_000004_create_employee_roles_table.php
 */

return new class extends Migration
{
    public function up()
    {
        Schema::create('EmployeeRole', function (Blueprint $table) {
            $table->bigInteger('EmployeeRoleID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->integer('ByUserID');
            $table->char('OperationCode', 1);
            
            $table->integer('EmployeeID');
            $table->bigInteger('RoleID');
            
            // Scope assignment (optional - NULL means global)
            $table->integer('OrganizationID')->nullable();
            $table->bigInteger('PositionID')->nullable();
            
            $table->boolean('IsActive')->default(true);
            $table->boolean('IsDelete')->default(false);
            $table->bigInteger('AssignedAt')->nullable(); // Timestamp when assigned
            
            // Foreign keys
            $table->foreign('EmployeeID')->references('EmployeeID')->on('Employee')->onDelete('cascade');
            $table->foreign('RoleID')->references('RoleID')->on('Role')->onDelete('cascade');
            $table->foreign('OrganizationID')->references('OrganizationID')->on('Organization')->onDelete('set null');
            $table->foreign('PositionID')->references('PositionID')->on('Position')->onDelete('set null');
            
            // One employee can only have one active role
            // Unique constraint removed to allow soft delete handling via IsActive + IsDelete
            
            $table->index('EmployeeID');
            $table->index('RoleID');
            $table->index(['EmployeeID', 'IsActive', 'IsDelete']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('EmployeeRole');
    }
};
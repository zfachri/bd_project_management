<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('EmployeeRole', function (Blueprint $table) {
            $table->bigInteger('EmployeeRoleID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->integer('ByUserID');
            $table->char('OperationCode', 1);

            $table->bigInteger('EmployeeID');
            $table->bigInteger('RoleID');

            // Scope (optional)
            $table->integer('OrganizationID')->nullable();
            $table->bigInteger('PositionID')->nullable();

            $table->boolean('IsActive')->default(true);
            $table->boolean('IsDelete')->default(false);
            $table->bigInteger('AssignedAt')->nullable();

            // Foreign keys
            $table->foreign('EmployeeID')
                  ->references('EmployeeID')
                  ->on('Employee')
                  ->onDelete('cascade');

            $table->foreign('RoleID')
                  ->references('RoleID')
                  ->on('Role')
                  ->onDelete('cascade');

            $table->foreign('OrganizationID')
                  ->references('OrganizationID')
                  ->on('Organization')
                  ->onDelete('set null');

            $table->foreign('PositionID')
                  ->references('PositionID')
                  ->on('Position')
                  ->onDelete('set null');

            $table->index('EmployeeID');
            $table->index('RoleID');
            $table->index(['EmployeeID', 'IsActive', 'IsDelete']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('EmployeeRole');
    }
};

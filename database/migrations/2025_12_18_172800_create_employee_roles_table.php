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
            $table->unsignedBigInteger('ByUserID');
            $table->char('OperationCode', 1);

            $table->unsignedBigInteger('EmployeeID');
            $table->unsignedBigInteger('RoleID');

            // Scope (optional)
            $table->unsignedBigInteger('OrganizationID')->nullable();
            $table->unsignedBigInteger('PositionID')->nullable();

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

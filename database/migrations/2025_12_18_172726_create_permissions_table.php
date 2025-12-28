<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Permission', function (Blueprint $table) {
            $table->bigInteger('PermissionID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->integer('ByUserID');
            $table->char('OperationCode', 1);

            $table->bigInteger('RoleID');
            $table->bigInteger('ModuleID');

            // CRUD permissions
            $table->boolean('CanCreate')->default(false);
            $table->boolean('CanView')->default(false);
            $table->boolean('CanEdit')->default(false);
            $table->boolean('CanDelete')->default(false);

            // Hierarchical access
            $table->boolean('CanAccessSubordinates')->default(false);
            $table->boolean('CanAccessParentOrg')->default(false);
            $table->boolean('CanAccessChildOrg')->default(false);

            // Scope level
            $table->enum('Scope', ['own', 'organization', 'position_tree', 'all'])
                  ->default('own');

            $table->boolean('IsDelete')->default(false);

            // Foreign keys
            $table->foreign('RoleID')
                  ->references('RoleID')
                  ->on('Role')
                  ->onDelete('cascade');

            $table->foreign('ModuleID')
                  ->references('ModuleID')
                  ->on('Module')
                  ->onDelete('cascade');

            // One role - one module
            $table->unique(['RoleID', 'ModuleID'], 'UK_Permission_Role_Module');

            $table->index('RoleID');
            $table->index('ModuleID');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Permission');
    }
};

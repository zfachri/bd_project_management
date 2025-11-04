<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('Position', function (Blueprint $table) {
            $table->unsignedBigInteger('PositionID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->unsignedBigInteger('ByUserID');
            $table->char('OperationCode', 1);
            $table->unsignedBigInteger('OrganizationID');
            $table->unsignedBigInteger('ParentPositionID')->nullable();
            $table->integer('LevelNo');
            $table->boolean('IsChild')->default(false);
            $table->string('PositionName', 100);
            $table->unsignedBigInteger('PositionLevelID');
            $table->integer('RequirementQuantity')->default(0);
            $table->boolean('IsActive')->default(true);
            $table->boolean('IsDelete')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Position');
    }
};

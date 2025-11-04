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
        Schema::create('EmployeePosition', function (Blueprint $table) {
            $table->unsignedBigInteger('EmployeePositionID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->unsignedBigInteger('ByUserID');
            $table->char('OperationCode', 1);
            $table->unsignedBigInteger('OrganizationID');
            $table->unsignedBigInteger('PositionID');
            $table->unsignedBigInteger('EmployeeID');
            $table->date('StartDate');
            $table->date('EndDate')->nullable();
            $table->string('Note', 200)->nullable();
            $table->boolean('IsActive')->default(true);
            $table->boolean('IsDelete')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('EmployeePosition');
    }
};

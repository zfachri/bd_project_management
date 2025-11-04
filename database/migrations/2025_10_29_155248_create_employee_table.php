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
        Schema::create('Employee', function (Blueprint $table) {
            $table->unsignedBigInteger('EmployeeID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->unsignedBigInteger('ByUserID');
            $table->char('OperationCode', 1);
            $table->unsignedBigInteger('OrganizationID');
            $table->char('GenderCode', 1);
            $table->date('DateOfBirth')->nullable();
            $table->date('JoinDate')->nullable();
            $table->date('ResignDate')->nullable();
            $table->string('Note', 200)->nullable();
            $table->boolean('IsDelete')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Employee');
    }
};

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
        Schema::create('ProjectAssignMember', function (Blueprint $table) {
            $table->bigInteger('ProjectAssignMemberID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->bigInteger('ByUserID');
            $table->char('OperationCode', 1)->comment('I-INSERT; U-UPDATE');
            $table->bigInteger('ProjectMemberID');
            $table->bigInteger('ProjectTaskID');

            // Indexes
            $table->index('ProjectMemberID');
            $table->index('ProjectTaskID');
            $table->index(['ProjectTaskID', 'ProjectMemberID']);
            
            // Foreign keys
            $table->foreign('ProjectMemberID')->references('ProjectMemberID')->on('ProjectMember')->onDelete('cascade');
            $table->foreign('ProjectTaskID')->references('ProjectTaskID')->on('ProjectTask')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ProjectAssignMember');
    }
};
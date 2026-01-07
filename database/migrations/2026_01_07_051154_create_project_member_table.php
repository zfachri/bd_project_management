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
        Schema::create('ProjectMember', function (Blueprint $table) {
            $table->bigInteger('ProjectMemberID')->primary();
            $table->bigInteger('ProjectID');
            $table->bigInteger('AtTimeStamp');
            $table->bigInteger('ByUserID');
            $table->char('OperationCode', 1)->comment('I-INSERT; U-UPDATE');
            $table->bigInteger('UserID');
            $table->boolean('IsActive')->default(true);
            $table->boolean('IsOwner')->default(false);
            $table->string('Title', 200)->nullable();

            // Indexes
            $table->index('ProjectID');
            $table->index('UserID');
            $table->index(['ProjectID', 'UserID']);
            $table->index(['ProjectID', 'IsOwner', 'IsActive']);
            
            // Foreign keys
            $table->foreign('ProjectID')->references('ProjectID')->on('Project')->onDelete('cascade');
            $table->foreign('UserID')->references('id')->on('User')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ProjectMember');
    }
};
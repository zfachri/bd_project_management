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
        Schema::create('ProjectTaskFile', function (Blueprint $table) {
            $table->bigInteger('ProjectTaskFileID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->bigInteger('ByUserID');
            $table->char('OperationCode', 1)->comment('I-INSERT; U-UPDATE; D-DELETE');
            $table->bigInteger('ProjectID');
            $table->bigInteger('ProjectTaskID');
            $table->string('OriginalFileName', 255);
            $table->string('ConvertedFileName', 255)->comment('Filename with random string');
            $table->string('DocumentPath', 500)->comment('PDF path for display');
            $table->string('DocumentUrl', 500)->comment('PDF URL for display');
            $table->string('DocumentOriginalPath', 500)->comment('Original file path');
            $table->string('DocumentOriginalUrl', 500)->comment('Original file URL');
            $table->boolean('IsDelete')->default(false);

            // Indexes
            $table->index('ProjectTaskID');
            $table->index('ProjectID');
            $table->index('IsDelete');
            $table->index(['ProjectTaskID', 'IsDelete']);
            
            // Foreign keys
            $table->foreign('ProjectID')->references('ProjectID')->on('Project')->onDelete('cascade');
            $table->foreign('ProjectTaskID')->references('ProjectTaskID')->on('ProjectTask')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ProjectTaskFile');
    }
};
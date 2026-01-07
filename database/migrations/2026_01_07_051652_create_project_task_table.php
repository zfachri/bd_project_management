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
        Schema::create('ProjectTask', function (Blueprint $table) {
            $table->bigInteger('ProjectTaskID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->bigInteger('ByUserID');
            $table->char('OperationCode', 1)->comment('I-INSERT; U-UPDATE; D-DELETE');
            $table->bigInteger('ProjectID');
            $table->bigInteger('ParentProjectTaskID')->nullable();
            $table->integer('SequenceNo')->nullable();
            $table->tinyInteger('PriorityCode')->comment('1-LOW; 2-MEDIUM; 3-HIGH');
            $table->string('TaskDescription', 200);
            $table->date('StartDate');
            $table->date('EndDate');
            $table->tinyInteger('ProgressCode')->default(0)->comment('0-INITIAL; 1-ON-PROGRESS; 2-COMPLETED; 3-DELAYED');
            $table->float('ProgressBar', 5, 2)->default(0)->comment('Progress percentage 0-100');
            $table->text('Note')->nullable();
            $table->boolean('IsDelete')->default(false);
            $table->boolean('IsCheck')->default(false)->comment('Checked by project owner');

            // Indexes
            $table->index('ProjectID');
            $table->index('ParentProjectTaskID');
            $table->index('IsDelete');
            $table->index(['ProjectID', 'IsDelete']);
            $table->index(['ProjectID', 'ProgressCode']);
            $table->index(['ProjectID', 'PriorityCode']);
            
            // Foreign key
            $table->foreign('ProjectID')->references('ProjectID')->on('Project')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ProjectTask');
    }
};
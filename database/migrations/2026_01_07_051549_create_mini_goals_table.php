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
        Schema::create('MiniGoal', function (Blueprint $table) {
            $table->bigInteger('MiniGoalID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->unsignedBigInteger('ByUserID');
            $table->char('OperationCode', 1)->comment('I-INSERT; U-UPDATE; D-DELETE');
            $table->bigInteger('ProjectID');
            $table->integer('SequenceNo')->nullable();
            $table->string('MiniGoalDescription', 200);
            $table->char('MiniGoalCategoryCode', 1)->comment('1-Currency($); 2-Percentage(%); 3-Quantity(#)');
            $table->integer('TargetValue')->default(0);
            $table->integer('ActualValue')->default(0);
            $table->boolean('IsDelete')->default(false);

            // Indexes
            $table->index('ProjectID');
            $table->index('IsDelete');
            $table->index(['ProjectID', 'IsDelete']);
            
            // Foreign key
            $table->foreign('ProjectID')->references('ProjectID')->on('Project')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('MiniGoal');
    }
};
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
        Schema::create('ProjectStatus', function (Blueprint $table) {
            $table->bigInteger('ProjectID')->primary();
            $table->char('ProjectStatusCode', 2)->comment('00-VOID; 10-NEW; 11-ON-PROGRESS; 12-HOLD; 99-COMPLETED');
            $table->string('ProjectStatusReason', 200)->nullable();
            $table->integer('TotalMember')->default(0);
            $table->integer('TotalTaskPriority1')->default(0);
            $table->integer('TotalTaskPriority2')->default(0);
            $table->integer('TotalTaskPriority3')->default(0);
            $table->integer('TotalTask')->default(0);
            $table->integer('TotalTaskProgress1')->default(0)->comment('INITIAL');
            $table->integer('TotalTaskProgress2')->default(0)->comment('ON-PROGRESS');
            $table->integer('TotalTaskProgress3')->default(0)->comment('COMPLETED');
            $table->integer('TotalTaskChecked')->default(0);
            $table->integer('TotalExpense')->default(0);
            $table->integer('TotalExpenseChecked')->default(0);
            $table->decimal('AccumulatedExpense', 12, 0)->default(0);
            $table->bigInteger('LastTaskUpdateAtTimeStamp')->nullable();
            $table->bigInteger('LastTaskUpdateByUserID')->nullable();
            $table->bigInteger('LastExpenseUpdateAtTimeStamp')->nullable();
            $table->bigInteger('LastExpenseUpdateByUserID')->nullable();

            // Foreign key
            $table->foreign('ProjectID')->references('ProjectID')->on('Project')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ProjectStatus');
    }
};
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
        Schema::create('Project', function (Blueprint $table) {
            $table->bigInteger('ProjectID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->bigInteger('ByUserID');
            $table->char('OperationCode', 1)->comment('I-INSERT; U-UPDATE; D-DELETE');
            $table->bigInteger('ParentProjectID')->nullable();
            $table->integer('LevelNo')->comment('1-PROJECT; 2-PHASE');
            $table->boolean('IsChild')->default(false);
            $table->bigInteger('ProjectCategoryID')->nullable();
            $table->text('ProjectDescription');
            $table->char('CurrencyCode', 3)->default('IDR');
            $table->decimal('BudgetAmount', 12, 0)->default(0);
            $table->boolean('IsDelete')->default(false);
            $table->date('StartDate');
            $table->date('EndDate');
            $table->tinyInteger('PriorityCode')->comment('1-LOW; 2-MEDIUM; 3-HIGH');

            // Indexes
            $table->index('ByUserID');
            $table->index('ParentProjectID');
            $table->index('IsDelete');
            $table->index(['StartDate', 'EndDate']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Project');
    }
};
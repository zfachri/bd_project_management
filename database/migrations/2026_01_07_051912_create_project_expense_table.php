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
        Schema::create('ProjectExpense', function (Blueprint $table) {
            $table->bigInteger('ProjectExpenseID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->bigInteger('ByUserID');
            $table->char('OperationCode', 1)->comment('I-INSERT; U-UPDATE; D-DELETE');
            $table->bigInteger('ProjectID');
            $table->date('ExpenseDate');
            $table->string('ExpenseNote', 200);
            $table->char('CurrencyCode', 3)->default('IDR');
            $table->decimal('ExpenseAmount', 12, 0);
            $table->boolean('IsDelete')->default(false);
            $table->boolean('IsCheck')->default(false)->comment('Checked by project owner');

            // Indexes
            $table->index('ProjectID');
            $table->index('IsDelete');
            $table->index(['ProjectID', 'IsDelete']);
            $table->index('ExpenseDate');
            
            // Foreign key
            $table->foreign('ProjectID')->references('ProjectID')->on('Project')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ProjectExpense');
    }
};
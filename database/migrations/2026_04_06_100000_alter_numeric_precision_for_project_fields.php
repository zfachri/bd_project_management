<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE `Project` MODIFY `BudgetAmount` DECIMAL(18,2) NOT NULL DEFAULT 0.00");
        DB::statement("ALTER TABLE `ProjectExpense` MODIFY `ExpenseAmount` DECIMAL(18,2) NOT NULL");
        DB::statement("ALTER TABLE `ProjectStatus` MODIFY `AccumulatedExpense` DECIMAL(18,2) NOT NULL DEFAULT 0.00");
        DB::statement("ALTER TABLE `MiniGoal` MODIFY `TargetValue` DECIMAL(18,2) NOT NULL DEFAULT 0.00");
        DB::statement("ALTER TABLE `MiniGoal` MODIFY `ActualValue` DECIMAL(18,2) NOT NULL DEFAULT 0.00");
        DB::statement("ALTER TABLE `ProjectTask` MODIFY `ProgressBar` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Progress percentage 0-100'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE `Project` MODIFY `BudgetAmount` DECIMAL(12,0) NOT NULL DEFAULT 0");
        DB::statement("ALTER TABLE `ProjectExpense` MODIFY `ExpenseAmount` DECIMAL(12,0) NOT NULL");
        DB::statement("ALTER TABLE `ProjectStatus` MODIFY `AccumulatedExpense` DECIMAL(12,0) NOT NULL DEFAULT 0");
        DB::statement("ALTER TABLE `MiniGoal` MODIFY `TargetValue` INT NOT NULL DEFAULT 0");
        DB::statement("ALTER TABLE `MiniGoal` MODIFY `ActualValue` INT NOT NULL DEFAULT 0");
        DB::statement("ALTER TABLE `ProjectTask` MODIFY `ProgressBar` FLOAT(5,2) NOT NULL DEFAULT 0 COMMENT 'Progress percentage 0-100'");
    }
};

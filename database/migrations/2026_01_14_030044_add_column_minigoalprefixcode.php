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
        Schema::table('MiniGoal', function (Blueprint $table) {
            $table->char('MiniGoalFirstPrefixCode', 10)->default('')->after('MiniGoalCategoryCode');
            $table->char('MiniGoalLastPrefixCode', 10)->default('')->after('MiniGoalFirstPrefixCode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('MiniGoal', function (Blueprint $table) {
            $table->dropColumn('MiniGoalFirstPrefixCode');
            $table->dropColumn('MiniGoalLastPrefixCode');
        });
    }
};
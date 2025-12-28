<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Module', function (Blueprint $table) {
            $table->bigInteger('ModuleID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->integer('ByUserID');
            $table->char('OperationCode', 1);

            $table->string('ModuleName', 100)->unique(); // e.g. JobDescription
            $table->string('DisplayName', 100);          // e.g. Job Description
            $table->string('Description', 500)->nullable();
            $table->integer('SortOrder')->default(0);
            $table->boolean('IsActive')->default(true);
            $table->boolean('IsDelete')->default(false);

            $table->index('ModuleName');
            $table->index('IsActive');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Module');
    }
};

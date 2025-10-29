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
        Schema::create('Organization', function (Blueprint $table) {
            $table->id('OrganizationID');
            $table->bigInteger('AtTimeStamp');
            $table->unsignedBigInteger('ByUserID');
            $table->char('OperationCode', 1);
            $table->unsignedBigInteger('ParentOrganizationID')->nullable();
            $table->integer('LevelNo');
            $table->boolean('IsChild')->default(false);
            $table->string('OrganizationName', 100);
            $table->boolean('IsActive')->default(true);
            $table->boolean('IsDelete')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Organization');
    }
};

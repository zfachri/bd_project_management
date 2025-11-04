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
        Schema::create('JobDescription', function (Blueprint $table) {
            $table->unsignedBigInteger('RecordID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->unsignedBigInteger('ByUserID');
            $table->char('OperationCode', 1);
            $table->unsignedBigInteger('OrganizationID');
            $table->unsignedBigInteger('PositionID');
            $table->text('JobDescription')->nullable();
            $table->text('MainTaskDescription')->nullable();
            $table->text('MainTaskMeasurement')->nullable();
            $table->text('InternalRelationshipDescription')->nullable();
            $table->text('InternalRelationshipObjective')->nullable();
            $table->text('ExternalRelationshipDescription')->nullable();
            $table->text('ExternalRelationshipObjective')->nullable();
            $table->text('TechnicalCompetency')->nullable();
            $table->text('SoftCompetency')->nullable();
            $table->boolean('IsDelete')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('JobDescription');
    }
};

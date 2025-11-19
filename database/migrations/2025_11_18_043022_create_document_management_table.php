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
        Schema::create('DocumentManagement', function (Blueprint $table) {
            $table->unsignedBigInteger('DocumentManagementID')->primary();
            $table->bigInteger('AtTimeStamp');
            $table->bigInteger('ByUserID');
            $table->char('OperationCode', 1)->default('I');
            $table->string('DocumentName', 255);
            $table->string('DocumentType', 255);
            $table->text('Description')->nullable();
            $table->text('Notes')->nullable();
            $table->bigInteger('OrganizationID');
            $table->integer('LatestVersionNo')->default(1);
            
            // Indexes
            $table->index('OrganizationID');
            $table->index('ByUserID');
            $table->index('DocumentType');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('DocumentManagement');
    }
};
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
        Schema::create('AuditLog', function (Blueprint $table) {
            $table->id('AuditLogID');
            $table->bigInteger('AtTimeStamp');
            $table->unsignedBigInteger('ByUserID');
            $table->char('OperationCode', 1);
            $table->string('ReferenceTable', 100);
            $table->unsignedBigInteger('ReferenceRecordID');
            $table->text('Data')->nullable();
            $table->string('Note', 200)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('AuditLog');
    }
};

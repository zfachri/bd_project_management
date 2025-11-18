<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('DocumentVersion', function (Blueprint $table) {
            $table->unsignedBigInteger('DocumentManagementID');
            $table->integer('VersionNo');
            $table->text('DocumentPath');
            $table->unsignedBigInteger('AtTimeStamp');

            // Composite primary key
            $table->primary(['DocumentManagementID', 'VersionNo']);

            // FK
            $table->foreign('DocumentManagementID')
                  ->references('DocumentManagementID')
                  ->on('DocumentManagement')
                  ->onUpdate('no action')
                  ->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('DocumentVersion');
    }
};

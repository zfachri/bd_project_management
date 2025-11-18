<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('DocumentRevision', function (Blueprint $table) {
            $table->increments('DocumentRevisionID');
            $table->unsignedBigInteger('DocumentManagementID');
            $table->unsignedBigInteger('ByUserID')->nullable();
            $table->text('Comment')->nullable();
            $table->enum('Status', ['request', 'approve', 'decline'])
                  ->default('request');
            $table->text('Notes')->nullable();
            $table->unsignedBigInteger('NotesByUserID')->nullable();

            // Foreign key
            $table->foreign('DocumentManagementID')
                  ->references('DocumentManagementID')
                  ->on('DocumentManagement')
                  ->onUpdate('no action')
                  ->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('DocumentRevision');
    }
};

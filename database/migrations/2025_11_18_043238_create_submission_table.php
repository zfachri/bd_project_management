<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('DocumentSubmission', function (Blueprint $table) {
            $table->unsignedBigInteger('DocumentSubmission')->primary();
            $table->unsignedBigInteger('ByUserID');
            $table->unsignedBigInteger('OrganizationID');
            $table->text('Comment')->nullable();
            $table->enum('Status', ['request', 'approve', 'decline'])
                  ->default('request');
            $table->text('Notes')->nullable();
            $table->unsignedBigInteger('NotesByUserID')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('DocumentSubmission');
    }
};

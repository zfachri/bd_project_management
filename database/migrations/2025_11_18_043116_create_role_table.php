<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('DocumentRole', function (Blueprint $table) {
            $table->increments('DocumentRoleID');
            $table->unsignedBigInteger('DocumentManagementID');
            $table->unsignedBigInteger('OrganizationID');
            $table->boolean('IsDownload')->default(false);
            $table->boolean('IsComment')->default(true);

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
        Schema::dropIfExists('DocumentRole');
    }
};

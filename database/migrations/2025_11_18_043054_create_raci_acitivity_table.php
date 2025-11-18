<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('RaciActivity', function (Blueprint $table) {
            $table->bigIncrements('RaciActivityID');
            $table->unsignedBigInteger('DocumentManagementID');
            $table->string('Activity', 255);
            $table->unsignedBigInteger('PIC');
            $table->enum('Status', ['Informed', 'Accountable', 'Consulted'])
                  ->default('Informed');

            // FK langsung dalam create()
            $table->foreign('DocumentManagementID')
                  ->references('DocumentManagementID')
                  ->on('DocumentManagement')
                  ->onUpdate('no action')
                  ->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('RaciActivity');
    }
};

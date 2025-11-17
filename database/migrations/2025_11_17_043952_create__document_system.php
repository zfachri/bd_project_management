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
        Schema::create('DocumentSystem', function (Blueprint $table) {
            $table->bigInteger('DocumentID')->primary()->comment('timestamp+random_number(5)');
            $table->bigInteger('AtTimeStamp')->comment('timestamp');
            $table->unsignedBigInteger('ByUserID')->comment('FK to User table');
            $table->char('OperationCode', 1)->comment("'I' - INSERT, 'U' - UPDATE, 'D' - DELETE");
            $table->string('ModuleName', 50)->comment('nama table bersangkutan contoh organization');
            $table->bigInteger('ModuleID')->comment('ID dari Module yang tautkan');
            $table->string('FileName', 255);
            $table->text('FilePath');
            $table->text('Note')->nullable();
            $table->boolean('IsDelete')->default(false)->comment('Soft delete ketika di search tanpa filter IsDelete true tidak akan terlihat');
            
            // Indexes for better query performance
            $table->index('ByUserID');
            $table->index(['ModuleName', 'ModuleID']);
            $table->index('IsDelete');
            $table->index('AtTimeStamp');
            
            // Foreign key constraint (assuming User table exists)
            // Uncomment if User table exists
            // $table->foreign('ByUserID')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('DocumentSystem');
    }
};
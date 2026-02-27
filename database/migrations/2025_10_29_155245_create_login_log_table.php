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
        Schema::create('LoginLog', function (Blueprint $table) {
            $table->unsignedBigInteger('LoginLogID')->primary();
            $table->unsignedBigInteger('UserID');
            $table->boolean('IsSuccessful')->default(false);
            $table->bigInteger('LoginTimeStamp');
            $table->json('LoginLocationJSON')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('LoginLog');
    }
};

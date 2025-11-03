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
        Schema::create('RefreshToken', function (Blueprint $table) {
            $table->id('RefreshTokenID');
            $table->unsignedBigInteger('UserID');
            $table->text('Token');
            $table->bigInteger('ExpiresAt');
            $table->boolean('IsUsed')->default(false);
            $table->bigInteger('UsedAt')->nullable();
            $table->bigInteger('CreatedAt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('RefreshToken');
    }
};
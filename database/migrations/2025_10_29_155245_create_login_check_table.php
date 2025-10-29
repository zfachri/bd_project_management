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
        Schema::create('LoginCheck', function (Blueprint $table) {
            $table->unsignedBigInteger('UserID')->primary();
            $table->char('UserStatusCode', 2)->default('99');
            $table->boolean('IsChangePassword')->default(true);
            $table->string('Salt', 36);
            $table->bigInteger('LastLoginTimeStamp')->nullable();
            $table->string('LastLoginLocationJSON', 100)->nullable();
            $table->integer('LastLoginAttemptCounter')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('LoginCheck');
    }
};

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
        Schema::create('OTP', function (Blueprint $table) {
            $table->id('OTPID');
            $table->bigInteger('AtTimeStamp');
            $table->bigInteger('ExpiryTimeStamp');
            $table->unsignedBigInteger('UserID');
            $table->char('OTPCategoryCode', 2);
            $table->char('OTP', 4);
            $table->boolean('IsUsed')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('OTP');
    }
};

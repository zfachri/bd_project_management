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
            Schema::table('DocumentVersion', function (Blueprint $table) {
            $table->string('DocumentOriginalPath', 500)->nullable()->after('DocumentUrl');
            $table->string('DocumentOriginalUrl', 500)->nullable()->after('DocumentOriginalPath');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('DocumentVersion', function (Blueprint $table) {
            $table->dropColumn('DocumentOriginalPath');
            $table->dropColumn('DocumentOriginalUrl');
        });
    }
};

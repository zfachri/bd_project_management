<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('DocumentVersion', function (Blueprint $table) {
            $table->string('DocumentUrl', 500)->nullable()->after('DocumentPath');
        });
    }

    public function down(): void
    {
        Schema::table('DocumentVersion', function (Blueprint $table) {
            $table->dropColumn('DocumentUrl');
        });
    }
};

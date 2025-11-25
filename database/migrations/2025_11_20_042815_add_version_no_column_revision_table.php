<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('DocumentRevision', function (Blueprint $table) {
            $table->integer('VersionNo')
                  ->after('DocumentManagementID')
                  ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('DocumentRevision', function (Blueprint $table) {
            $table->dropColumn('VersionNo');
        });
    }
};

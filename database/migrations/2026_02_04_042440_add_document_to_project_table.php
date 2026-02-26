<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDocumentToProjectTable extends Migration
{
    public function up()
    {
        Schema::table('Project', function (Blueprint $table) {
            $table->string('DocumentPath', 500)->nullable()->after('PriorityCode');
            $table->string('DocumentUrl', 500)->nullable()->after('DocumentPath');
            $table->string('DocumentOriginalPath', 500)->nullable()->after('DocumentUrl');
            $table->string('DocumentOriginalUrl', 500)->nullable()->after('DocumentOriginalPath');
        });
    }

    public function down()
    {
        Schema::table('Project', function (Blueprint $table) {
            $table->dropColumn([
                'DocumentPath',
                'DocumentUrl',
                'DocumentOriginalPath',
                'DocumentOriginalUrl',
            ]);
        });
    }
}


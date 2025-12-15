<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Tambahkan 'Responsible' ke enum Status
        DB::statement("ALTER TABLE `RaciActivity` MODIFY COLUMN `Status` ENUM('Informed', 'Accountable', 'Consulted', 'Responsible') NOT NULL DEFAULT 'Informed'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cek apakah ada data dengan Status = 'Responsible'
        $hasResponsible = DB::table('RaciActivity')
            ->where('Status', 'Responsible')
            ->exists();

        if ($hasResponsible) {
            // Update semua 'Responsible' menjadi 'Informed' sebelum alter
            DB::table('RaciActivity')
                ->where('Status', 'Responsible')
                ->update(['Status' => 'Informed']);
        }

        // Kembalikan ke enum semula
        DB::statement("ALTER TABLE `RaciActivity` MODIFY COLUMN `Status` ENUM('Informed', 'Accountable', 'Consulted') NOT NULL DEFAULT 'Informed'");
    }
};
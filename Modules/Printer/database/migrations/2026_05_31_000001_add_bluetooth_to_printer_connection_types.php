<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `printers` MODIFY `connection_type` ENUM('tcp','spooler','usb_raw','bluetooth') NOT NULL DEFAULT 'tcp'");
        }
    }

    public function down(): void
    {
        DB::table('printers')
            ->where('connection_type', 'bluetooth')
            ->update(['connection_type' => 'tcp']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `printers` MODIFY `connection_type` ENUM('tcp','spooler','usb_raw') NOT NULL DEFAULT 'tcp'");
        }
    }
};

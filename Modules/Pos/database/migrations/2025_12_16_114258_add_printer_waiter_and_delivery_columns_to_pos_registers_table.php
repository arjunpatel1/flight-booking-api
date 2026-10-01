<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Printer\Models\Printer;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pos_registers', function (Blueprint $table) {
            $table->foreignIdFor(Printer::class, "waiter_printer_id")
                ->nullable()
                ->after('bill_printer_id');
            $table->foreignIdFor(Printer::class, "delivery_printer_id")
                ->nullable()
                ->after('waiter_printer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_registers', function (Blueprint $table) {
            $table->dropForeignIdFor(Printer::class, "waiter_printer_id");
            $table->dropForeignIdFor(Printer::class, "delivery_printer_id");
            $table->dropColumn('waiter_printer_id');
            $table->dropColumn('delivery_printer_id');
        });
    }
};

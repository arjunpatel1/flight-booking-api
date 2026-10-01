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
            $table->foreignIdFor(Printer::class, "invoice_printer_id")
                ->nullable()
                ->after('code');
            $table->foreignIdFor(Printer::class, "bill_printer_id")
                ->nullable()
                ->after('invoice_printer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_registers', function (Blueprint $table) {
            $table->dropForeignIdFor(Printer::class, "invoice_printer_id");
            $table->dropForeignIdFor(Printer::class, "bill_printer_id");
            $table->dropColumn('invoice_printer_id');
            $table->dropColumn('bill_printer_id');
        });
    }
};

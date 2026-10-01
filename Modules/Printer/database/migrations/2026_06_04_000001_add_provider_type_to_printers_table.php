<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Printer\Enum\PrinterProviderType;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            if (!Schema::hasColumn('printers', 'provider_type')) {
                $table->string('provider_type', 50)
                    ->default(PrinterProviderType::WindowsAgent->value)
                    ->after('connection_type')
                    ->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            if (Schema::hasColumn('printers', 'provider_type')) {
                $table->dropColumn('provider_type');
            }
        });
    }
};

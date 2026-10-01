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
        Schema::table('users', function (Blueprint $table) {
            $table->json('category_slugs')->nullable()
                ->after('is_active');
            $table->foreignIdFor(Printer::class)
                ->nullable()
                ->after('category_slugs');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeignIdFor(Printer::class);
            $table->dropColumn('printer_id');
            $table->dropColumn('category_slugs');
        });
    }
};

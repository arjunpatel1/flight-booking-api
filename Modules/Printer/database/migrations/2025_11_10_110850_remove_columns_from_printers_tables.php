<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Pos\Models\PosRegister;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropColumn('role');
            $table->dropColumn('category_slugs');
            $table->dropForeign(['pos_register_id']);
            $table->dropColumn('pos_register_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->enum('role', ['kitchen', 'cashier'])->after('name');
            $table->json('category_slugs')->nullable()->after('connection_type');
            $table->foreignIdFor(PosRegister::class)
                ->nullable()
                ->after('category_slugs')
                ->constrained()
                ->nullOnDelete();
        });
    }
};

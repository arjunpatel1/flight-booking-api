<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('products')
            ->whereNotNull('deleted_at')
            ->whereNotNull('sku')
            ->update(['sku' => null]);
    }

    public function down(): void
    {
        // Released identifiers cannot be reconstructed safely.
    }
};

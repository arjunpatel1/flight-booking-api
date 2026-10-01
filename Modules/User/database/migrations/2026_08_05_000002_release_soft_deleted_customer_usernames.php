<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('users')
            ->whereNotNull('deleted_at')
            ->whereNotNull('username')
            ->update(['username' => null]);
    }

    public function down(): void
    {
        // Released identifiers cannot be reconstructed safely.
    }
};

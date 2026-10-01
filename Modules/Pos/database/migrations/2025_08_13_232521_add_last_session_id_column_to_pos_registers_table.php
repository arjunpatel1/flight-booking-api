<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Pos\Models\PosSession;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pos_registers', function (Blueprint $table) {
            $table->foreignIdFor(PosSession::class, 'last_session_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('pos_sessions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_registers', function (Blueprint $table) {
            $table->dropForeign(['last_session_id']);
            $table->dropColumn('last_session_id');
        });
    }
};

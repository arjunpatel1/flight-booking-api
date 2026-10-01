<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pos_terminal_devices', function (Blueprint $table): void {
            $table->text('push_token')->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('pos_terminal_devices', function (Blueprint $table): void {
            $table->dropColumn('push_token');
        });
    }
};

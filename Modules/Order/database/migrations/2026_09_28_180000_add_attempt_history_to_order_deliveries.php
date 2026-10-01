<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_deliveries', 'attempt_history')) {
                $table->json('attempt_history')->nullable()->after('quote_history');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table): void {
            if (Schema::hasColumn('order_deliveries', 'attempt_history')) {
                $table->dropColumn('attempt_history');
            }
        });
    }
};

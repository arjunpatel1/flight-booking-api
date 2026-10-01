<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('table_reservations', function (Blueprint $table) {
            $table->foreignId('customer_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->index(
                ['customer_id', 'reservation_date', 'status'],
                'table_reservations_customer_date_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('table_reservations', function (Blueprint $table) {
            $table->dropIndex('table_reservations_customer_date_status_index');
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (!Schema::hasColumn('branches', 'quick_pay_amounts')) {
                $table->json('quick_pay_amounts')->nullable()->after('payment_methods');
            }

            if (!Schema::hasColumn('branches', 'hide_waiter_selection')) {
                $table->boolean('hide_waiter_selection')->default(false)->after('quick_pay_amounts');
            }

            if (!Schema::hasColumn('branches', 'appearance_settings')) {
                $table->json('appearance_settings')->nullable()->after('hide_waiter_selection');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $columns = collect(['quick_pay_amounts', 'hide_waiter_selection', 'appearance_settings'])
                ->filter(fn(string $column) => Schema::hasColumn('branches', $column))
                ->all();

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};

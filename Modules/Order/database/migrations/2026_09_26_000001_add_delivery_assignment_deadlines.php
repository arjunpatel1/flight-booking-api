<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            if (! Schema::hasColumn('branches', 'delivery_assignment_timeout_minutes')) {
                $table->unsignedSmallInteger('delivery_assignment_timeout_minutes')->default(15)->after('delivery_radius_km');
            }
        });
        Schema::table('order_deliveries', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_deliveries', 'assignment_deadline_at')) {
                $table->timestamp('assignment_deadline_at')->nullable()->after('rider_assigned_at')->index();
            }
            if (! Schema::hasColumn('order_deliveries', 'assignment_escalated_at')) {
                $table->timestamp('assignment_escalated_at')->nullable()->after('assignment_deadline_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_deliveries', fn (Blueprint $table) => $table->dropColumn(['assignment_deadline_at', 'assignment_escalated_at']));
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('delivery_assignment_timeout_minutes'));
    }
};

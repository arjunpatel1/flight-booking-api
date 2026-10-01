<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('order_deliveries')
            ->where('status', 'rider_searching')
            ->whereNull('rider_assigned_at')
            ->whereNull('assignment_deadline_at')
            ->orderBy('id')
            ->chunkById(100, function ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    $timeout = max(5, min(120, (int) (DB::table('branches')
                        ->where('id', $delivery->branch_id)
                        ->value('delivery_assignment_timeout_minutes') ?: 15)));
                    $startedAt = $delivery->assigned_at ?: $delivery->booking_completed_at ?: $delivery->created_at;
                    DB::table('order_deliveries')->where('id', $delivery->id)->update([
                        'assignment_deadline_at' => \Illuminate\Support\Carbon::parse($startedAt)->addMinutes($timeout),
                    ]);
                }
            });
    }

    public function down(): void {}
};

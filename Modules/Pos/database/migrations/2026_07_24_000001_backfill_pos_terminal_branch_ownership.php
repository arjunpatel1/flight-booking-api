<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (
            ! Schema::hasTable('pos_terminal_devices')
            || ! Schema::hasTable('users')
            || ! Schema::hasColumn('pos_terminal_devices', 'branch_id')
            || ! Schema::hasColumn('pos_terminal_devices', 'created_by')
            || ! Schema::hasColumn('users', 'branch_id')
        ) {
            return;
        }

        DB::table('pos_terminal_devices')
            ->whereNull('branch_id')
            ->whereNotNull('created_by')
            ->orderBy('id')
            ->eachById(function ($device) {
                $branchId = DB::table('users')
                    ->where('id', $device->created_by)
                    ->whereNotNull('branch_id')
                    ->value('branch_id');

                if ($branchId) {
                    DB::table('pos_terminal_devices')
                        ->where('id', $device->id)
                        ->whereNull('branch_id')
                        ->update(['branch_id' => $branchId]);
                }
            }, 100);
    }

    public function down(): void
    {
        // Forward-only ownership repair; valid terminal assignments must not be
        // erased during rollback.
    }
};

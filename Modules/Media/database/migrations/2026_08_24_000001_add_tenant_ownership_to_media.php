<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
            $table->index(['tenant_id', 'created_at'], 'media_tenant_created_idx');
        });

        DB::table('media')->whereNotNull('created_by')->orderBy('id')->chunkById(500, function ($rows): void {
            $tenantByUser = DB::table('users')
                ->whereIn('id', $rows->pluck('created_by')->filter()->unique())
                ->pluck('tenant_id', 'id');

            foreach ($rows as $row) {
                $tenantId = $tenantByUser[$row->created_by] ?? null;
                if ($tenantId) {
                    DB::table('media')->where('id', $row->id)->update(['tenant_id' => $tenantId]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
        });

        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex('media_tenant_created_idx');
            $table->dropColumn('tenant_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('whats_app_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->unsignedBigInteger('branch_id')->nullable()->after('tenant_id');
            $table->index(['tenant_id', 'created_at'], 'whatsapp_logs_tenant_created_index');
            $table->index(['tenant_id', 'branch_id', 'created_at'], 'whatsapp_logs_scope_created_index');
        });

        $tenantIds = DB::table('tenants')->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true]);
        $branches = DB::table('branches')->pluck('tenant_id', 'id');

        DB::table('whats_app_logs')->whereNotNull('request_payload')->orderBy('id')->chunkById(500, function ($logs) use ($branches, $tenantIds) {
            foreach ($logs as $log) {
                $payload = json_decode((string) $log->request_payload, true);
                $tenantId = isset($payload['tenant_id']) ? (int) $payload['tenant_id'] : null;
                $branchId = isset($payload['branch_id']) ? (int) $payload['branch_id'] : null;
                $validTenant = $tenantId !== null && isset($tenantIds[$tenantId]);
                $validBranch = $branchId !== null
                    && isset($branches[$branchId])
                    && (int) $branches[$branchId] === $tenantId;

                if ($validTenant) {
                    DB::table('whats_app_logs')->where('id', $log->id)->update([
                        'tenant_id' => $tenantId,
                        'branch_id' => $validBranch ? $branchId : null,
                    ]);
                }
            }
        });

        Schema::table('whats_app_logs', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whats_app_logs', function (Blueprint $table) {
            $table->dropIndex('whatsapp_logs_scope_created_index');
            $table->dropIndex('whatsapp_logs_tenant_created_index');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};

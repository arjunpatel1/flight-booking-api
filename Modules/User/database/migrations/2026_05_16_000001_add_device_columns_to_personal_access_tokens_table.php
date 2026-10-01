<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (!Schema::hasColumn('personal_access_tokens', 'ip_address')) {
                $table->string('ip_address', 45)->nullable()->after('last_used_at');
            }

            if (!Schema::hasColumn('personal_access_tokens', 'user_agent')) {
                $table->string('user_agent')->nullable()->after('ip_address');
            }

            if (!Schema::hasColumn('personal_access_tokens', 'device_name')) {
                $table->string('device_name')->nullable()->after('user_agent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $columns = collect(['ip_address', 'user_agent', 'device_name'])
                ->filter(fn(string $column) => Schema::hasColumn('personal_access_tokens', $column))
                ->all();

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};

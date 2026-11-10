<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A built-in role whose permissions a platform admin edited by hand.
     * permission:sync-default-roles leaves such roles alone until they are
     * reset to their defaults.
     */
    public function up(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        if (! Schema::hasColumn($table, 'permissions_customized')) {
            Schema::table($table, function (Blueprint $table) {
                $table->boolean('permissions_customized')->default(false)->after('built_in');
            });
        }
    }

    public function down(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        if (Schema::hasColumn($table, 'permissions_customized')) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('permissions_customized');
            });
        }
    }
};

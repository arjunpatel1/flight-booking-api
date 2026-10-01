<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_tenant_assignments', function (Blueprint $table) {
            // A receiving number must resolve to exactly one tenant. Reusing a
            // number across tenants would make an authenticated webhook
            // ambiguous even when its provider signature is valid.
            $table->unique('phone_number_id', 'wa_assignment_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_tenant_assignments', function (Blueprint $table) {
            $table->dropUnique('wa_assignment_phone_unique');
        });
    }
};

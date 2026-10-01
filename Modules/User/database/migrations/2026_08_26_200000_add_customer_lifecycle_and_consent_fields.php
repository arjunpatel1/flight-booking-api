<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('anniversary_date')->nullable()->after('date_of_birth');
            $table->boolean('whatsapp_marketing_consent')->default(false)->after('anniversary_date');
            $table->timestamp('whatsapp_marketing_consented_at')->nullable()->after('whatsapp_marketing_consent');
            $table->string('whatsapp_consent_source', 80)->nullable()->after('whatsapp_marketing_consented_at');
            $table->timestamp('whatsapp_opted_out_at')->nullable()->after('whatsapp_consent_source');
            $table->index(['tenant_id', 'anniversary_date']);
            $table->index(['tenant_id', 'whatsapp_marketing_consent', 'whatsapp_opted_out_at'], 'users_tenant_whatsapp_consent_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'anniversary_date']);
            $table->dropIndex('users_tenant_whatsapp_consent_index');
            $table->dropColumn([
                'anniversary_date', 'whatsapp_marketing_consent',
                'whatsapp_marketing_consented_at', 'whatsapp_consent_source',
                'whatsapp_opted_out_at',
            ]);
        });
    }
};

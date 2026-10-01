<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('partner_api_credentials') && ! Schema::hasColumn('partner_api_credentials', 'signature_version')) {
            Schema::table('partner_api_credentials', function (Blueprint $table) {
                // Existing credentials remain v1; newly issued credentials use v2.
                $table->unsignedTinyInteger('signature_version')->default(1)->after('secret_ciphertext');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('partner_api_credentials') && Schema::hasColumn('partner_api_credentials', 'signature_version')) {
            Schema::table('partner_api_credentials', fn (Blueprint $table) => $table->dropColumn('signature_version'));
        }
    }
};

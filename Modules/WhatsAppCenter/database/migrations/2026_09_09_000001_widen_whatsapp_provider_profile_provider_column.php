<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original column was an enum limited to ['meta', 'msg91']. NexMsg (and
     * any future provider) must be storable without another schema change, so
     * the column becomes a short string validated at the request layer instead.
     */
    public function up(): void
    {
        Schema::table('whatsapp_provider_profiles', function (Blueprint $table) {
            $table->string('provider', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_provider_profiles', function (Blueprint $table) {
            $table->enum('provider', ['meta', 'msg91'])->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string("legal_name")->nullable()->after("name");
            $table->string("vat_tin")->nullable()->after("registration_number");
            $table->renameColumn('address', 'address_line1');
            $table->string("address_line1")->nullable()->change();
            $table->string("address_line2")->nullable()->after("address_line1");
            $table->string("city")->nullable()->after("address_line2");
            $table->string("state")->nullable()->after("city");
            $table->string("postal_code")->nullable()->after("state");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->renameColumn('address_line1', 'address');
            $table->text('address')->nullable()->change();
            $table->dropColumn("legal_name");
            $table->dropColumn("vat_tin");
            $table->dropColumn("address_line2");
            $table->dropColumn("city");
            $table->dropColumn("state");
            $table->dropColumn("postal_code");
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Invoice\Enums\InvoicePartyType;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoice_parties', function (Blueprint $table) {
            $table->id();
            $table->enum('type', InvoicePartyType::values());
            $table->string('legal_name', 255);
            $table->string('vat_tin', 64)->nullable()->comment("VAT Number / TIN");
            $table->string('cr_number', 64)->nullable()->comment("Commercial Registration");
            $table->string('address_line1', 255)->nullable();
            $table->string('address_line2', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country_code', 2);
            $table->string('postal_code', 20)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_parties');
    }
};

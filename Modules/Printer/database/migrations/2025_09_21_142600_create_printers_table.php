<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Printer\Enum\PrinterConnectionType;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->json('name');
            $table->enum('role', ['kitchen', 'cashier']);
            $table->enum('connection_type', PrinterConnectionType::values())
                ->default(PrinterConnectionType::Tcp->value);
            $table->json('category_slugs')->nullable();
            $table->json('options')->nullable();
            $table->active();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};

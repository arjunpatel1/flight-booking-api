<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Payment\Enums\PaymentMethod;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('method', PaymentMethod::values())->change();
        });
    }

    public function down(): void
    {
        // Existing UPI rows cannot be losslessly converted to another rail.
        // Keep the widened enum during rollback.
    }
};

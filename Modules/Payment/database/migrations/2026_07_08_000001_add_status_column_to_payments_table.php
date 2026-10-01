<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Payment\Enums\PaymentStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'status')) {
                $table->enum('status', PaymentStatus::values())
                    ->default(PaymentStatus::Completed->value)
                    ->after('type')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};

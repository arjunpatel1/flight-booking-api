<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_payroll_runs', function (Blueprint $table) {
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->index();
            $table->string('payment_method', 40)->nullable();
            $table->string('payment_reference', 160)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employee_payroll_runs', function (Blueprint $table) {
            $table->dropForeign(['paid_by']);
            $table->dropForeign(['voided_by']);
            $table->dropColumn(['paid_by', 'paid_at', 'payment_method', 'payment_reference', 'voided_by', 'voided_at', 'void_reason']);
        });
    }
};

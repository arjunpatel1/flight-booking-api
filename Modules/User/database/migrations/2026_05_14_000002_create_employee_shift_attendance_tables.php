<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\User\Models\User;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'user_id', 'is_active'], 'emp_shifts_branch_user_active_index');
        });

        Schema::create('employee_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignId('employee_shift_id')->nullable()->constrained('employee_shifts')->nullOnDelete();
            $table->dateTime('clock_in_at');
            $table->dateTime('clock_out_at')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->string('status')->default('open')->index();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'user_id', 'clock_in_at'], 'emp_attendance_branch_user_clock_in_index');
            $table->index(['status', 'clock_in_at'], 'emp_attendance_status_clock_in_index');
        });

        Schema::create('employee_compensations', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('pay_type')->default('monthly')->index();
            $table->decimal('base_rate', 12, 2)->default(0);
            $table->decimal('overtime_rate', 12, 2)->default(0);
            $table->unsignedSmallInteger('standard_daily_minutes')->default(480);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'user_id', 'is_active'], 'emp_comp_branch_user_active_index');
        });

        Schema::create('employee_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->constrained()->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('status')->default('draft')->index();
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('deduction_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->foreignIdFor(User::class, 'approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'period_from', 'period_to'], 'emp_payroll_run_branch_period_idx');
        });

        Schema::create('employee_payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->constrained()->cascadeOnDelete();
            $table->foreignId('employee_payroll_run_id')->constrained('employee_payroll_runs')->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('regular_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->decimal('base_amount', 12, 2)->default(0);
            $table->decimal('overtime_amount', 12, 2)->default(0);
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('deduction_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['employee_payroll_run_id', 'user_id'], 'emp_payslip_run_user_unique');
        });

        Schema::create('employee_payslip_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_payslip_id')->constrained('employee_payslips')->cascadeOnDelete();
            $table->string('type')->default('other')->index();
            $table->string('label');
            $table->decimal('amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payslip_deductions');
        Schema::dropIfExists('employee_payslips');
        Schema::dropIfExists('employee_payroll_runs');
        Schema::dropIfExists('employee_compensations');
        Schema::dropIfExists('employee_attendances');
        Schema::dropIfExists('employee_shifts');
    }
};

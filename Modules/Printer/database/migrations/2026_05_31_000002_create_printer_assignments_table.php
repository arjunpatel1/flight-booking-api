<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Printer\Enum\PrintContentType;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('printer_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->enum('scope', ['default', 'print_type', 'user', 'role']);
            $table->enum('print_type', PrintContentType::values())->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $table->foreignId('printer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'scope', 'print_type']);
            $table->index(['branch_id', 'scope', 'user_id', 'print_type']);
            $table->index(['branch_id', 'scope', 'role_id', 'print_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_assignments');
    }
};

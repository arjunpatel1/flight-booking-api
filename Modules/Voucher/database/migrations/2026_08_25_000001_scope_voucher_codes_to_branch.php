<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropUnique('vouchers_code_unique');
            $table->unique(['branch_id', 'code'], 'vouchers_branch_code_unique');
        });

        Schema::table('gift_cards', function (Blueprint $table): void {
            $table->dropUnique('gift_cards_code_unique');
            $table->unique(['branch_id', 'code'], 'gift_cards_branch_code_unique');
        });
    }

    public function down(): void
    {
        // MySQL may use the composite unique index to satisfy the branch_id
        // foreign key after dropping its redundant implicit index. Restore a
        // dedicated branch index before removing the composite constraint.
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->index('branch_id', 'vouchers_branch_id_index');
        });

        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropUnique('vouchers_branch_code_unique');
            $table->unique('code', 'vouchers_code_unique');
        });

        Schema::table('gift_cards', function (Blueprint $table): void {
            $table->dropUnique('gift_cards_branch_code_unique');
            $table->unique('code', 'gift_cards_code_unique');
        });
    }
};

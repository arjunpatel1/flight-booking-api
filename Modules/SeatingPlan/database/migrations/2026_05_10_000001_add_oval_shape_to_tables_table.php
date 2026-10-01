<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\SeatingPlan\Enums\TableShape;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `tables` MODIFY `shape` ENUM('circle', 'rectangle', 'square', 'oval') NOT NULL DEFAULT '"
                . TableShape::Square->value
                . "'"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('tables')
                ->where('shape', TableShape::Oval->value)
                ->update(['shape' => TableShape::Square->value]);

            DB::statement(
                "ALTER TABLE `tables` MODIFY `shape` ENUM('circle', 'rectangle', 'square') NOT NULL DEFAULT '"
                . TableShape::Square->value
                . "'"
            );
        }
    }
};

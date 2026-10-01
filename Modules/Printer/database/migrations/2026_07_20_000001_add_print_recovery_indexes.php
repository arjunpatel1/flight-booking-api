<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addIndexIfMissing('print_jobs', 'print_jobs_status_lease_idx', ['status', 'lease_until']);
        $this->addIndexIfMissing('print_jobs', 'print_jobs_status_created_idx', ['status', 'created_at']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('print_jobs', 'print_jobs_status_created_idx');
        $this->dropIndexIfExists('print_jobs', 'print_jobs_status_lease_idx');
    }

    private function addIndexIfMissing(string $table, string $name, array $columns): void
    {
        if (! $this->tableHasColumns($table, $columns) || $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $name) {
            $table->index($columns, $name);
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name) {
            $table->dropIndex($name);
        });
    }

    private function tableHasColumns(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function indexExists(string $table, string $name): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        if (DB::getDriverName() === 'mysql') {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $name)
                ->exists();
        }

        if (DB::getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn (object $index) => ($index->name ?? null) === $name);
        }

        return false;
    }
};

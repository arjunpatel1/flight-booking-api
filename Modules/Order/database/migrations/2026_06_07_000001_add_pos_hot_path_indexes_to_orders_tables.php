<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addIndexIfMissing('orders', 'orders_branch_date_status_idx', ['branch_id', 'order_date', 'status']);
        $this->addIndexIfMissing('orders', 'orders_branch_status_created_idx', ['branch_id', 'status', 'created_at']);
        $this->addIndexIfMissing('orders', 'orders_branch_waiter_date_idx', ['branch_id', 'waiter_id', 'order_date']);
        $this->addIndexIfMissing('orders', 'orders_branch_table_status_idx', ['branch_id', 'table_id', 'status']);
        $this->addIndexIfMissing('orders', 'orders_branch_register_status_idx', ['branch_id', 'pos_register_id', 'status']);
        $this->addIndexIfMissing('orders', 'orders_branch_payment_date_idx', ['branch_id', 'payment_status', 'order_date']);

        $this->addIndexIfMissing('order_products', 'order_products_order_status_idx', ['order_id', 'status']);
        $this->addIndexIfMissing('order_products', 'order_products_product_status_idx', ['product_id', 'status']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('order_products', 'order_products_product_status_idx');
        $this->dropIndexIfExists('order_products', 'order_products_order_status_idx');

        $this->dropIndexIfExists('orders', 'orders_branch_payment_date_idx');
        $this->dropIndexIfExists('orders', 'orders_branch_register_status_idx');
        $this->dropIndexIfExists('orders', 'orders_branch_table_status_idx');
        $this->dropIndexIfExists('orders', 'orders_branch_waiter_date_idx');
        $this->dropIndexIfExists('orders', 'orders_branch_status_created_idx');
        $this->dropIndexIfExists('orders', 'orders_branch_date_status_idx');
    }

    private function addIndexIfMissing(string $table, string $name, array $columns): void
    {
        if (!$this->tableHasColumns($table, $columns) || $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $name) {
            $table->index($columns, $name);
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (!Schema::hasTable($table) || !$this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name) {
            $table->dropIndex($name);
        });
    }

    private function tableHasColumns(string $table, array $columns): bool
    {
        if (!Schema::hasTable($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function indexExists(string $table, string $name): bool
    {
        if (!Schema::hasTable($table)) {
            return false;
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $name)
                ->exists();
        }

        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn(object $index) => ($index->name ?? null) === $name);
        }

        return false;
    }
};

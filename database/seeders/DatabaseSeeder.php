<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Root database seeder.
 *
 * The application is a modular monolith (nwidart/laravel-modules): every domain
 * lives in Modules/{Name} and owns its own {Name}DatabaseSeeder. The canonical
 * way to seed is therefore `module:seed --all`, which is exactly what the web
 * installer runs (see InstallerController::database()).
 *
 * Laravel's `migrate:fresh --seed` / `db:seed` resolve THIS class by default,
 * so we delegate to the same module-seed mechanism. This keeps a single source
 * of truth for seed order and demo-data behaviour (config('app.seed_demo_data'))
 * across both the installer and plain artisan seeding.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->call('module:seed', [
            '--all' => true,
            // Allow seeding in production parity environments / CI the same way
            // the installer does; module seeders remain idempotent.
            '--force' => true,
        ]);
    }
}

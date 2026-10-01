<?php

namespace Modules\User\Database\Seeders;

use App\NexDine;
use Illuminate\Database\Seeder;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (NexDine::seedDemoData()) {
            User::factory()
                ->count(5)
                ->withRandomRole([
                    DefaultRole::Customer,
                ])
                ->create();
        }
    }
}

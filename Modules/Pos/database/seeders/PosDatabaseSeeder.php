<?php

namespace Modules\Pos\Database\Seeders;

use App\NexDine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Modules\Branch\Models\Branch;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Models\PosSession;
use Modules\Setting\Models\Setting;
use Modules\User\Models\User;

class PosDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->setDefault('pos_best_seller_window_days', 30);
        $this->setDefault('pos_best_seller_limit', 10);
        $this->setDefault('pos_menu_cache_minutes', 5);
        $this->call([
            KitchenStationSeeder::class,
        ]);

        if (NexDine::seedDemoData()) {
            foreach (Branch::query()->get() as $branch) {
                $registers = collect([
                    $this->upsertRegister($branch, 'POS-'.$branch->id.'-MAIN', ['en' => 'Main POS', 'ar' => 'نقطة البيع الرئيسية']),
                    $this->upsertRegister($branch, 'POS-'.$branch->id.'-WAITER', ['en' => 'Waiter POS', 'ar' => 'نقطة بيع النادل']),
                ]);

                $this->posSessionSeeder($branch, $registers);
            }
            $this->call([
                PosCashMovementSeeder::class,
            ]);
        }
    }

    /**
     * Pos session seeder
     *
     * @param Branch $branch
     * @param Collection $registers
     * @return void
     */
    private function posSessionSeeder(Branch $branch, Collection $registers): void
    {
        if (! User::query()->where('branch_id', $branch->id)->exists()) {
            return;
        }

        foreach ($registers as $register) {
            if (! PosSession::query()
                ->where('branch_id', $branch->id)
                ->where('pos_register_id', $register->id)
                ->where('status', PosSessionStatus::Closed)
                ->exists()) {
                PosSession::factory()
                    ->forBranch($branch->id, $register->id, PosSessionStatus::Closed)
                    ->create();
            }

            if (! PosSession::query()
                ->where('branch_id', $branch->id)
                ->where('pos_register_id', $register->id)
                ->where('status', PosSessionStatus::Open)
                ->exists()) {
                PosSession::factory()
                    ->forBranch($branch->id, $register->id, PosSessionStatus::Open)
                    ->create();
            }
        }
    }

    private function upsertRegister(Branch $branch, string $code, array $name): PosRegister
    {
        return PosRegister::query()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->updateOrCreate(
                ['code' => $code],
                [
                    'branch_id' => $branch->id,
                    'name' => $name,
                    'note' => ['en' => 'Created by demo installer seed.', 'ar' => 'تم إنشاؤها بواسطة بيانات العرض.'],
                    'is_active' => true,
                ]
            );
    }

    private function setDefault(string $key, mixed $value): void
    {
        if (blank(Setting::get($key))) {
            Setting::set($key, $value);
        }
    }
}

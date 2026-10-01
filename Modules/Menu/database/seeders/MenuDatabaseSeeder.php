<?php

namespace Modules\Menu\Database\Seeders;

use App\NexDine;
use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\Menu\Models\Menu;
use Modules\Menu\Models\OnlineMenu;

class MenuDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (NexDine::seedDemoData()) {
            foreach (Branch::query()->get() as $branch) {
                $mainMenu = $this->upsertMenu($branch, [
                    'name' => ['en' => 'Main Menu', 'ar' => 'القائمة الرئيسية'],
                    'description' => ['en' => 'Default dine-in menu for daily service.', 'ar' => 'القائمة الافتراضية للخدمة اليومية.'],
                    'is_active' => true,
                ]);

                $this->upsertMenu($branch, [
                    'name' => ['en' => 'Takeaway Menu', 'ar' => 'قائمة الطلبات الخارجية'],
                    'description' => ['en' => 'Fast service menu for takeaway and delivery.', 'ar' => 'قائمة سريعة للطلبات الخارجية والتوصيل.'],
                    'is_active' => false,
                ]);

                OnlineMenu::query()
                    ->withOutGlobalBranchPermission()
                    ->withoutGlobalActive()
                    ->updateOrCreate(
                        [
                            'branch_id' => $branch->id,
                            'slug' => "branch-{$branch->id}-online-menu",
                        ],
                        [
                            'menu_id' => $mainMenu->id,
                            'name' => ['en' => "{$branch->name} Online Menu", 'ar' => 'القائمة الإلكترونية'],
                            'is_active' => true,
                        ]
                    );
            }
        } else {
            $branch = Branch::query()->main()->first();

            if (! $branch) {
                return;
            }

            $this->upsertMenu($branch, [
                'name' => "Main Menu",
                'description' => null,
                'is_active' => false,
            ]);
        }
    }

    private function upsertMenu(Branch $branch, array $attributes): Menu
    {
        $name = $attributes['name'];
        $englishName = is_array($name) ? $name['en'] : $name;

        $menu = Menu::query()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->where('branch_id', $branch->id)
            ->where('name->en', $englishName)
            ->first();

        $menu ??= new Menu(['branch_id' => $branch->id]);

        $menu->fill($attributes + ['branch_id' => $branch->id]);
        $menu->save();

        return $menu;
    }
}

<?php

namespace Modules\Option\Database\Seeders;

use Arr;
use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\Option\Models\Option;
use Modules\Option\Models\OptionValue;

class OptionSeeder extends Seeder
{
    /**
     * Options
     *
     * @var array|array[]
     */
    private array $options = [
        [
            'name' => ['en' => 'Size', 'ar' => 'الحجم'],
            'type' => 'radio',
            'is_required' => true,
            'values' => [
                ['label' => ['en' => 'Small', 'ar' => 'صغير'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Medium', 'ar' => 'متوسط'], 'price' => 2, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Large', 'ar' => 'كبير'], 'price' => 4, 'price_type' => 'fixed'],
            ],
        ],

        [
            'name' => ['en' => 'Dressing', 'ar' => 'الصوص للسلطة'],
            'type' => 'select',
            'is_required' => false,
            'values' => [
                ['label' => ['en' => 'Vinaigrette', 'ar' => 'خل'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Caesar', 'ar' => 'سيزر'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Thousand Island', 'ar' => 'ألف جزيرة'], 'price' => 0, 'price_type' => 'fixed'],
            ],
        ],

        [
            'name' => ['en' => 'Spice Level', 'ar' => 'درجة الحارة'],
            'type' => 'radio',
            'is_required' => true,
            'values' => [
                ['label' => ['en' => 'Mild', 'ar' => 'خفيف'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Medium', 'ar' => 'متوسط'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Hot', 'ar' => 'حار'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Extra Hot', 'ar' => 'حار جداً'], 'price' => 0, 'price_type' => 'fixed'],
            ],
        ],

        [
            'name' => ['en' => 'Add-ons', 'ar' => 'إضافات'],
            'type' => 'multiple_select',
            'is_required' => false,
            'values' => [
                ['label' => ['en' => 'Extra Cheese', 'ar' => 'جبنة إضافية'], 'price' => 1.5, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Bacon', 'ar' => 'لحم بقري'], 'price' => 2.0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Avocado', 'ar' => 'أفوكادو'], 'price' => 1.8, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Mushrooms', 'ar' => 'فطر'], 'price' => 1.0, 'price_type' => 'fixed'],
            ],
        ],

        [
            'name' => ['en' => 'Bread Type', 'ar' => 'نوع الخبز'],
            'type' => 'select',
            'is_required' => true,
            'values' => [
                ['label' => ['en' => 'White', 'ar' => 'أبيض'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Whole Wheat', 'ar' => 'قمح كامل'], 'price' => 0.5, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Gluten Free', 'ar' => 'خالٍ من الغلوتين'], 'price' => 1.0, 'price_type' => 'fixed'],
            ],
        ],

        [
            'name' => ['en' => 'Cooking Level', 'ar' => 'درجة النضج'],
            'type' => 'radio',
            'is_required' => false,
            'values' => [
                ['label' => ['en' => 'Rare', 'ar' => 'نادر'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Medium Rare', 'ar' => 'نضج متوسط'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Medium', 'ar' => 'متوسط'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Well Done', 'ar' => 'ناضج جيداً'], 'price' => 0, 'price_type' => 'fixed'],
            ],
        ],

        [
            'name' => ['en' => 'Side Choice', 'ar' => 'اختيار الطبق الجانبي'],
            'type' => 'select',
            'is_required' => false,
            'values' => [
                ['label' => ['en' => 'Fries', 'ar' => 'بطاطس مقلية'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Salad', 'ar' => 'سلطة'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Rice', 'ar' => 'أرز'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Sweet Corn', 'ar' => 'ذرة حلوة'], 'price' => 0.5, 'price_type' => 'fixed'],
            ],
        ],

        [
            'name' => ['en' => 'Sauce', 'ar' => 'الصلصة'],
            'type' => 'checkbox',
            'is_required' => false,
            'values' => [
                ['label' => ['en' => 'Ketchup', 'ar' => 'كاتشب'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Mayo', 'ar' => 'مايونيز'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'BBQ', 'ar' => 'صلصة باربكيو'], 'price' => 0.5, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Garlic', 'ar' => 'ثوم'], 'price' => 0.5, 'price_type' => 'fixed'],
            ],
        ],

        [
            'name' => ['en' => 'Cheese Type', 'ar' => 'نوع الجبن'],
            'type' => 'select',
            'is_required' => false,
            'values' => [
                ['label' => ['en' => 'Cheddar', 'ar' => 'شيدر'], 'price' => 0.7, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Swiss', 'ar' => 'سويسري'], 'price' => 0.7, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Mozzarella', 'ar' => 'موزاريلا'], 'price' => 0.7, 'price_type' => 'fixed'],
                ['label' => ['en' => 'No Cheese', 'ar' => 'بدون جبن'], 'price' => 0, 'price_type' => 'fixed'],
            ],
        ],

        [
            'name' => ['en' => 'Pizza Crust', 'ar' => 'نوع العجينة'],
            'type' => 'radio',
            'is_required' => true,
            'values' => [
                ['label' => ['en' => 'Thin', 'ar' => 'رفيعة'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Regular', 'ar' => 'عادية'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Stuffed', 'ar' => 'محشوة'], 'price' => 2.5, 'price_type' => 'fixed'],
            ],
        ],

        [
            'name' => ['en' => 'Pizza Cut', 'ar' => 'تقطيع البيتزا'],
            'type' => 'select',
            'is_required' => false,
            'values' => [
                ['label' => ['en' => 'Normal slices', 'ar' => 'شرائح عادية'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Square cut', 'ar' => 'مربعات'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'No cut', 'ar' => 'بدون تقطيع'], 'price' => 0, 'price_type' => 'fixed'],
            ],
        ],

        [
            'name' => ['en' => 'Toppings', 'ar' => 'الإضافات'],
            'type' => 'multiple_select',
            'is_required' => false,
            'values' => [
                ['label' => ['en' => 'Olives', 'ar' => 'زيتون'], 'price' => 0.8, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Peppers', 'ar' => 'فلفل'], 'price' => 0.6, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Onions', 'ar' => 'بصل'], 'price' => 0.5, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Pepperoni', 'ar' => 'بيبروني'], 'price' => 1.5, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Mushrooms', 'ar' => 'فطر'], 'price' => 0.7, 'price_type' => 'fixed'],
            ],
        ],

        [
            'name' => ['en' => 'Milk Choice', 'ar' => 'اختيار الحليب'],
            'type' => 'select',
            'is_required' => false,
            'values' => [
                ['label' => ['en' => 'Whole', 'ar' => 'كامل الدسم'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Skim', 'ar' => 'قليل الدسم'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Almond', 'ar' => 'لوز'], 'price' => 0.8, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Oat', 'ar' => 'شوفان'], 'price' => 0.8, 'price_type' => 'fixed'],
            ],
        ],

        [
            'name' => ['en' => 'Sugar Level', 'ar' => 'مستوى السكر'],
            'type' => 'radio',
            'is_required' => false,
            'values' => [
                ['label' => ['en' => 'No sugar', 'ar' => 'بدون سكر'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Less', 'ar' => 'قليل'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Normal', 'ar' => 'عادي'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Extra', 'ar' => 'زيادة'], 'price' => 0, 'price_type' => 'fixed'],
            ],
        ],

        [
            'name' => ['en' => 'Ice Level', 'ar' => 'مستوى الثلج'],
            'type' => 'radio',
            'is_required' => false,
            'values' => [
                ['label' => ['en' => 'No Ice', 'ar' => 'بدون ثلج'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Light', 'ar' => 'قليل'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Normal', 'ar' => 'عادي'], 'price' => 0, 'price_type' => 'fixed'],
                ['label' => ['en' => 'Extra', 'ar' => 'زيادة'], 'price' => 0, 'price_type' => 'fixed'],
            ],
        ],
        [
            'name' => ['en' => 'Extra Shot', 'ar' => 'شوت إضافي'],
            'type' => 'checkbox',
            'is_required' => false,
            'values' => [
                ['label' => ['en' => '1 Shot', 'ar' => 'شوت واحد'], 'price' => 0.8, 'price_type' => 'fixed'],
                ['label' => ['en' => '2 Shots', 'ar' => 'شوتان'], 'price' => 1.5, 'price_type' => 'fixed'],
            ],
        ],

        // --- TEXT / TEXTAREA -----------------------------------------------------

        [
            'name' => ['en' => 'Special Instructions', 'ar' => 'تعليمات خاصة'],
            'type' => 'textarea',
            'is_required' => false,
            'values' => [
                ['label' => null, 'price' => 0, 'price_type' => 'fixed'],
            ],
        ],

        [
            'name' => ['en' => 'Name on Cup', 'ar' => 'الاسم على الكوب'],
            'type' => 'text',
            'is_required' => false,
            'values' => [
                ['label' => null, 'price' => 0, 'price_type' => 'fixed'],
            ],
        ],

        // --- DATE / TIME --------------------------------------------

        [
            'name' => ['en' => 'Pickup Date', 'ar' => 'تاريخ الاستلام'],
            'type' => 'date',
            'is_required' => false,
            'values' => [
                ['label' => null, 'price' => 0, 'price_type' => 'fixed'],
            ],
        ],

        [
            'name' => ['en' => 'Pickup Time', 'ar' => 'وقت الاستلام'],
            'type' => 'time',
            'is_required' => false,
            'values' => [
                ['label' => null, 'price' => 0, 'price_type' => 'fixed'],
            ],
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $branches = Branch::query()->get();

        foreach ($branches as $branch) {
            foreach ($this->options as $bp) {
                $option = Option::query()
                    ->withOutGlobalBranchPermission()
                    ->where('branch_id', $branch->id)
                    ->where('name->en', $bp['name']['en'])
                    ->first();

                $option ??= new Option(['branch_id' => $branch->id]);

                $option->fill([
                    'branch_id' => $branch->id,
                    'name' => $bp['name'],
                    'type' => $bp['type'],
                    'is_required' => $bp['is_required'],
                    'is_global' => false,
                ]);
                $option->save();

                foreach (Arr::get($bp, 'values', []) as $v) {
                    $valueQuery = OptionValue::query()
                        ->withOutGlobalBranchPermission()
                        ->where('branch_id', $branch->id)
                        ->where('option_id', $option->id);

                    if (is_array($v['label'])) {
                        $valueQuery->where('label->en', $v['label']['en']);
                    } else {
                        $valueQuery->whereNull('label');
                    }

                    $value = $valueQuery->first();
                    $value ??= new OptionValue([
                        'branch_id' => $branch->id,
                        'option_id' => $option->id,
                    ]);

                    $value->fill([
                        'option_id' => $option->id,
                        'label' => $v['label'],
                        'branch_id' => $branch->id,
                        'price' => $v['price'],
                        'price_type' => $v['price_type'],
                    ]);
                    $value->save();
                }
            }
        }
    }
}

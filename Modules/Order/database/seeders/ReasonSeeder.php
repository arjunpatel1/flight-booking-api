<?php

namespace Modules\Order\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Order\Enums\ReasonType;
use Modules\Order\Models\Reason;

class ReasonSeeder extends Seeder
{
    /**
     * Default Cancellation reasons
     *
     * @var array
     */
    private array $cancellationReasons = [
        ['en' => "Customer changed mind", "ar" => "لقد غير العميل رأيه"],
        ['en' => "Customer did not show up", "ar" => "لم يحضر العميل"],
        ['en' => "Wrong order placed by customer", "ar" => "طلب خاطئ قدمه العميل"],
        ['en' => "Customer requested different payment method", "ar" => "طلب العميل طريقة دفع مختلفة"],
        ['en' => "Customer complaint", "ar" => "شكوى العميل"],
        ['en' => "Item out of stock", "ar" => "المنتج غير متوفر"],
        ['en' => "Kitchen delay", "ar" => "تأخير المطبخ"],
        ['en' => "Staff error in order entry", "ar" => "خطأ الموظف في إدخال الطلب"],
        ['en' => "Order duplicated", "ar" => "تكرار الطلب"],
        ['en' => "Payment failed", "ar" => "فشل الدفع"],
        ['en' => "Fraud suspected", "ar" => "الاشتباه في الاحتيال"],
        ['en' => "Technical issue", "ar" => "مشكلة تقنية"],
    ];

    /**
     * Default Refund reasons
     *
     * @var array
     */
    private array $refundReasons = [
        ['en' => "Customer changed mind", 'ar' => "لقد غير العميل رأيه"],
        ['en' => "Wrong item prepared", 'ar' => "تم تحضير عنصر خاطئ"],
        ['en' => "Missing item(s)", 'ar' => "عناصر مفقودة"],
        ['en' => "Food undercooked", 'ar' => "الطعام غير مطهو جيداً"],
        ['en' => "Food overcooked/burnt", 'ar' => "الطعام مطهو أكثر من اللازم / محروق"],
        ['en' => "Food spoiled / poor quality", 'ar' => "الطعام فاسد / ذو جودة سيئة"],
        ['en' => "Not as described on menu", 'ar' => "مختلف عن الوصف في القائمة"],
        ['en' => "Order took too long", 'ar' => "استغرق الطلب وقتاً طويلاً"],
        ['en' => "Order lost / not prepared", 'ar' => "تم فقد الطلب / لم يتم تحضيره"],
        ['en' => "Wrong order delivered", 'ar' => "تم تسليم طلب خاطئ"],
        ['en' => "Spilled or damaged food", 'ar' => "طعام مسكوب أو تالف"],
        ['en' => "Incorrect customization", 'ar' => "تخصيص غير صحيح"],
        ['en' => "Duplicate charge", 'ar' => "تم خصم المبلغ مرتين"],
        ['en' => "Wrong amount charged", 'ar' => "تم خصم مبلغ خاطئ"],
        ['en' => "Technical / POS error", 'ar' => "خطأ تقني / في النظام"],
        ['en' => "Manager approved cancellation", 'ar' => "إلغاء معتمد من المدير"],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->cancellationReasons as $reason) {
            Reason::query()
                ->create([
                    'name' => $reason,
                    'type' => ReasonType::Cancellation,
                    'is_active' => true,
                ]);
        }

        foreach ($this->refundReasons as $reason) {
            Reason::query()
                ->create([
                    'name' => $reason,
                    'type' => ReasonType::Refund,
                    'is_active' => true,
                ]);
        }
    }
}

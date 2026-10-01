<?php
namespace Tests\Unit\Notification;
use Illuminate\Support\Facades\Validator;
use Modules\Setting\Enums\SettingSection;
use Modules\Setting\Http\Requests\Api\V1\SaveSettingRequest;
use Tests\TestCase;
class StaffAlertSettingValidationTest extends TestCase {
    private function validates(array $data): bool {
        $request=new class extends SaveSettingRequest { public SettingSection $section = SettingSection::Notifications; };
        $rules=array_filter($request->rules(),fn($key)=>str_starts_with($key,'order_phone_alert'),ARRAY_FILTER_USE_KEY);
        return Validator::make($data,$rules)->passes();
    }
    public function test_international_distinct_numbers_and_sources_are_accepted(): void {
        $this->assertTrue($this->validates(['order_phone_alert_numbers'=>['+917389175732','+919876543210'],'order_phone_alert_sources'=>['whatsapp','partner']]));
    }
    public function test_invalid_duplicate_or_excessive_numbers_are_rejected(): void {
        $this->assertFalse($this->validates(['order_phone_alert_numbers'=>['7389175732']]));
        $this->assertFalse($this->validates(['order_phone_alert_numbers'=>['+917389175732','+917389175732']]));
        $this->assertFalse($this->validates(['order_phone_alert_numbers'=>array_map(fn($i)=>'+9198765432'.sprintf('%02d',$i),range(1,11))]));
    }
    public function test_unknown_sources_and_unsafe_template_ids_are_rejected(): void {
        $this->assertFalse($this->validates(['order_phone_alert_sources'=>['unknown']]));
        $this->assertFalse($this->validates(['order_phone_alert_template_id'=>'https://untrusted.example/template']));
        $this->assertTrue($this->validates(['order_phone_alerts_enabled'=>false,'order_phone_alert_numbers'=>[],'order_phone_alert_sources'=>[],'order_phone_alert_template_id'=>'']));
    }
}

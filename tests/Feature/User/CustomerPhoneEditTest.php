<?php

namespace Tests\Feature\User;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\Role;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * "Customer Edit Page Requires a New Phone Number Every Time" — re-saving a
 * customer without touching the phone must not be rejected as a duplicate.
 */
class CustomerPhoneEditTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    private function makeCustomer(string $phone, string $iso = 'JO'): User
    {
        $role = Role::findOrCreate(DefaultRole::Customer->value, 'api');
        $customer = User::factory()->create(['is_active' => true]);
        $customer->forceFill([
            'phone' => $phone,
            'phone_country_iso_code' => $iso,
        ])->save();
        $customer->assignRole($role);

        return $customer->refresh();
    }

    private function payload(User $customer, array $overrides = []): array
    {
        return [
            'name' => $customer->name,
            'phone' => $customer->phone,
            'phone_country_iso_code' => $customer->phone_country_iso_code,
            'is_active' => true,
            ...$overrides,
        ];
    }

    public function test_resaving_a_customer_with_its_own_phone_is_accepted(): void
    {
        $this->actingAsUserWithPermissions(['admin.customers.edit']);
        $customer = $this->makeCustomer('+962795527918');

        $this->putJson("/api/v1/customers/{$customer->id}", $this->payload($customer))
            ->assertOk();
    }

    /**
     * Phones are stored however the creating surface formatted them, while the
     * edit request normalises to E.164 before validating. A customer saved with
     * a spaced number must still be editable.
     */
    public function test_resaving_a_customer_stored_with_a_formatted_phone_is_accepted(): void
    {
        $this->actingAsUserWithPermissions(['admin.customers.edit']);
        $customer = $this->makeCustomer('+962 7 9552 7918');

        $this->putJson("/api/v1/customers/{$customer->id}", $this->payload($customer))
            ->assertOk();
    }

    public function test_taking_another_customers_phone_is_still_rejected(): void
    {
        $this->actingAsUserWithPermissions(['admin.customers.edit']);
        $taken = $this->makeCustomer('+962795527918');
        $editing = $this->makeCustomer('+962795527919');

        $this->putJson(
            "/api/v1/customers/{$editing->id}",
            $this->payload($editing, ['phone' => $taken->phone])
        )->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }

    /**
     * Regression: national_phone is a computed accessor, but UserResource gated
     * it on the raw-column check, so it always resolved to null. Both the
     * customer and user edit forms bind their phone field to it, leaving the
     * field empty and forcing a new number on every save.
     */
    public function test_the_edit_payload_carries_the_national_phone(): void
    {
        $this->actingAsUserWithPermissions(['admin.customers.edit', 'admin.customers.show']);
        $customer = $this->makeCustomer('+962795527918');

        $this->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('body.phone', '+962 7 9552 7918')
            ->assertJsonPath('body.national_phone', '07 9552 7918');
    }

    public function test_a_customer_without_a_phone_reports_a_null_national_phone(): void
    {
        $this->actingAsUserWithPermissions(['admin.customers.edit', 'admin.customers.show']);
        $customer = $this->makeCustomer('+962795527918');
        $customer->forceFill(['phone' => null])->save();

        $this->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('body.national_phone', null);
    }
}

<?php

namespace Tests\Unit\Saas;

use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Workspace\ActivationKeyService;
use Tests\TestCase;

/**
 * The activation key contract.
 *
 * A key typed into the browser, the waiter app or read down a phone line must
 * behave identically everywhere, so these assertions are mirrored in
 * `src/utils/activationKey.spec.ts`. If one side changes, both must.
 */
class ActivationKeyServiceTest extends TestCase
{
    private function service(): ActivationKeyService
    {
        return app(ActivationKeyService::class);
    }

    private function tenant(int $id = 7, string $slug = 'ghee-dosa'): Tenant
    {
        $tenant = new Tenant();
        $tenant->id = $id;
        $tenant->slug = $slug;

        return $tenant;
    }

    public function test_it_issues_a_grouped_key_of_the_configured_length(): void
    {
        $service = $this->service();
        $key = $service->forTenant($this->tenant());

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}(-[A-Z0-9]{4})+$/', $key);
        $this->assertSame($service->keyLength(), strlen(str_replace('-', '', $key)));
    }

    public function test_the_key_is_stable_for_a_tenant(): void
    {
        $this->assertSame(
            $this->service()->forTenant($this->tenant()),
            $this->service()->forTenant($this->tenant()),
        );
    }

    public function test_it_never_emits_characters_that_can_be_misread(): void
    {
        $key = str_replace('-', '', $this->service()->forTenant($this->tenant()));

        foreach (['0', '1', 'I', 'L', 'O', 'U'] as $ambiguous) {
            $this->assertStringNotContainsString($ambiguous, $key);
        }
    }

    public function test_it_accepts_the_key_however_it_was_pasted(): void
    {
        $service = $this->service();
        $tenant = $this->tenant();
        $key = $service->forTenant($tenant);

        foreach ([
            $key,
            strtolower($key),
            str_replace('-', '', $key),
            str_replace('-', ' ', $key),
            "  {$key}  ",
        ] as $variant) {
            $this->assertTrue($service->matches($tenant, $variant), "Rejected: {$variant}");
        }
    }

    public function test_the_checksum_catches_a_single_mistyped_character(): void
    {
        $service = $this->service();
        $raw = str_replace('-', '', $service->forTenant($this->tenant()));
        $replacement = $raw[0] === '2' ? '3' : '2';

        $this->assertFalse($service->isWellFormed($replacement . substr($raw, 1)));
    }

    public function test_the_checksum_catches_transposed_characters(): void
    {
        $service = $this->service();
        $raw = str_replace('-', '', $service->forTenant($this->tenant()));

        if ($raw[0] === $raw[1]) {
            $this->markTestSkipped('First two characters are identical for this key.');
        }

        $this->assertFalse($service->isWellFormed($raw[1] . $raw[0] . substr($raw, 2)));
    }

    public function test_a_key_from_another_restaurant_is_rejected(): void
    {
        $service = $this->service();
        $mine = $this->tenant();
        $theirs = $this->tenant(8, 'other-place');

        $this->assertNotSame($service->forTenant($mine), $service->forTenant($theirs));
        $this->assertFalse($service->matches($theirs, $service->forTenant($mine)));
    }

    public function test_a_structurally_valid_key_for_another_tenant_still_fails_ownership(): void
    {
        $service = $this->service();
        $theirKey = $service->forTenant($this->tenant(8, 'other-place'));

        // Well-formed proves it was typed correctly, never that it is yours.
        $this->assertTrue($service->isWellFormed($theirKey));
        $this->assertFalse($service->matches($this->tenant(), $theirKey));
    }

    public function test_one_time_challenge_keys_are_random_and_self_checking(): void
    {
        $service = $this->service();
        $first = $service->randomKey();
        $second = $service->randomKey();

        $this->assertTrue($service->isWellFormed($first));
        $this->assertTrue($service->isWellFormed($second));
        $this->assertNotSame($first, $second);
    }
}

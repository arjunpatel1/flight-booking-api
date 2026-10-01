<?php

namespace Tests\Unit\Saas;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ActivationSignatureTest extends TestCase
{
    public function test_activation_configuration_urls_are_temporary_and_tenant_bound(): void
    {
        $expiresAt = now()->addMinutes(10);
        $url = URL::temporarySignedRoute(
            'api.v1.saas.client-config.signed',
            $expiresAt,
            ['slug' => 'restaurant-a'],
            absolute: false,
        );

        $request = Request::create($url);

        $this->assertTrue(URL::hasValidRelativeSignature($request));
        $this->assertStringContainsString('/restaurant-a/signed', $url);
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringContainsString('signature=', $url);

        $foreignTenantRequest = Request::create(
            str_replace('/restaurant-a/signed', '/restaurant-b/signed', $url),
        );

        $this->assertFalse(URL::hasValidRelativeSignature($foreignTenantRequest));
    }
}

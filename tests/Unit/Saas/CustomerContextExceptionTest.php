<?php

namespace Tests\Unit\Saas;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Mockery;
use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Http\Middleware\ResolveCustomerAppContext;
use Modules\Saas\Services\CustomerApp\CustomerAppSessionService;
use Modules\Saas\Support\TenantContext;
use Modules\User\Models\User;
use Tests\TestCase;

class CustomerContextExceptionTest extends TestCase
{
    public function test_staff_wildcard_token_cannot_enter_browser_customer_flow(): void
    {
        $context = new TenantContext;
        $context->setId(18);
        $sessions = Mockery::mock(CustomerAppSessionService::class);
        $sessions->shouldReceive('resolve')->once()->with('', Mockery::type(User::class))
            ->andThrow(new CustomerAppAuthorizationException('SESSION_REQUIRED', 'Session required.'));
        $middleware = new ResolveCustomerAppContext($sessions, $context);
        $staff = new User;
        $staff->withAccessToken(new PersonalAccessToken(['abilities' => ['*']]));
        $request = Request::create('/v1/customer-app/orders/test', 'POST');
        $request->setUserResolver(fn () => $staff);

        $response = $middleware->handle($request, function () {
            $this->fail('Staff credentials must not authorize a browser customer request.');
        });

        $this->assertContains($response->getStatusCode(), [401, 403]);
        $this->assertNull($context->id());
    }

    public function test_browser_validation_is_preserved_and_context_is_cleared(): void
    {
        $context = new TenantContext;
        $sessions = Mockery::mock(CustomerAppSessionService::class);
        $middleware = new class($sessions, $context) extends ResolveCustomerAppContext
        {
            protected function resolveBrowserCustomer(Request $request, User $customer): void
            {
                app(TenantContext::class)->setId(18);
            }
        };
        $this->app->instance(TenantContext::class, $context);
        $customer = new User;
        $customer->withAccessToken(new PersonalAccessToken(['abilities' => ['customer']]));
        $request = Request::create('/v1/customer-app/orders/test', 'POST');
        $request->setUserResolver(fn () => $customer);
        $expected = ValidationException::withMessages(['menu_id' => 'Invalid menu']);

        try {
            $middleware->handle($request, function () use ($context, $expected) {
                $this->assertSame(18, $context->id());
                throw $expected;
            });
            $this->fail('Validation must reach the API exception handler.');
        } catch (ValidationException $actual) {
            $this->assertSame($expected, $actual);
            $this->assertSame(422, $actual->status);
        }
        $this->assertNull($context->id());
    }
}

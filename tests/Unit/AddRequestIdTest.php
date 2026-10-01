<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Modules\Core\Http\Middleware\AddRequestId;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class AddRequestIdTest extends TestCase
{
    public function test_invalid_client_request_id_is_replaced_with_a_safe_uuid(): void
    {
        $request = Request::create('/api/v1/partner/health', 'GET', server: ['HTTP_X_REQUEST_ID' => "invalid\r\nvalue"]);
        $response = (new AddRequestId())->handle($request, fn () => response()->noContent());

        $this->assertTrue(Uuid::isValid((string) $response->headers->get('X-Request-ID')));
        $this->assertSame($response->headers->get('X-Request-ID'), $request->attributes->get('request_id'));
    }

    public function test_valid_client_uuid_is_preserved_for_correlation(): void
    {
        $uuid = (string) Uuid::uuid4();
        $request = Request::create('/api/v1/partner/health', 'GET', server: ['HTTP_X_REQUEST_ID' => $uuid]);
        $response = (new AddRequestId())->handle($request, fn () => new Response());

        $this->assertSame($uuid, $response->headers->get('X-Request-ID'));
    }
}

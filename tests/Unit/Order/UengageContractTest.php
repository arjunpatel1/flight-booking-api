<?php

namespace Tests\Unit\Order;

use Illuminate\Support\Facades\Http;
use Modules\Order\Delivery\DeliveryLocation;
use Modules\Order\Delivery\DeliveryQuote;
use Modules\Order\Delivery\ProviderUnavailable;
use Modules\Order\Delivery\UengageClient;
use Modules\Order\Delivery\UengageDeliveryProvider;
use Modules\Order\Delivery\UengageStatus;
use Modules\Order\Enums\DeliveryStatus;
use Tests\TestCase;

class UengageContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'delivery.integration_enabled' => true,
            'delivery.sandbox_enabled' => true,
            'delivery.booking_enabled' => true,
            'delivery.cancellation_enabled' => true,
            'delivery.uengage.environment' => 'sandbox',
            'delivery.uengage.production_enabled' => false,
        ]);
    }

    public function test_serviceability_uses_exact_v13_endpoint_header_and_payload(): void
    {
        Http::fake(['https://riderapi-staging.uengage.in/getServiceability' => Http::response([
            'status' => '200',
            'serviceability' => ['riderServiceAble' => true, 'locationServiceAble' => true],
            'payouts' => ['total' => 60.77, 'price' => 51.95, 'tax' => 8.82],
        ])]);

        $body = app(UengageClient::class)->serviceability(
            'test-token', '89', new DeliveryLocation(28.39232449, 77.34029003),
            new DeliveryLocation(28.409860071476828, 77.31229623779655),
        );

        $this->assertSame(60.77, $body['payouts']['total']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://riderapi-staging.uengage.in/getServiceability'
            && $request->hasHeader('access-token', 'test-token')
            && $request['store_id'] === '89'
            && $request['pickupDetails']['latitude'] === 28.39232449
            && $request['dropDetails']['longitude'] === 77.31229623779655);
    }

    public function test_provider_normalizes_one_auto_allocation_quote_without_invented_eta_or_reference(): void
    {
        $this->fakeSettings(['delivery_uengage_api_key' => 'secret', 'delivery_uengage_store_id' => '89']);
        Http::fake(['*/getServiceability' => Http::response([
            'status' => '200',
            'serviceability' => ['riderServiceAble' => true, 'locationServiceAble' => true],
            'payouts' => ['total' => 60.77, 'price' => 51.95, 'tax' => 8.82],
        ])]);

        $quotes = app(UengageDeliveryProvider::class)->quotes(
            new DeliveryLocation(28, 77), new DeliveryLocation(28.1, 77.1), 'ORD-1', false,
        );

        $this->assertCount(1, $quotes);
        $this->assertSame('uengage_auto', $quotes[0]->partnerCode);
        $this->assertSame(60.77, $quotes[0]->cost);
        $this->assertNull($quotes[0]->etaMinutes);
        $this->assertNull($quotes[0]->reference);
    }

    public function test_live_unavailable_shape_returns_a_non_serviceable_quote(): void
    {
        $this->fakeSettings(['delivery_uengage_api_key' => 'secret', 'delivery_uengage_store_id' => '69840']);
        Http::fake(['*/getServiceability' => Http::response([
            'status' => '400',
            'serviceability' => ['riderServiceAble' => false, 'locationServiceAble' => false],
            'payouts' => ['message' => 'Rider not Available currently!'],
        ])]);

        $quotes = app(UengageDeliveryProvider::class)->quotes(
            new DeliveryLocation(22.94595528, 76.04929352),
            new DeliveryLocation(22.9623, 76.0536), 'ORD-UNAVAILABLE', false,
        );

        $this->assertCount(1, $quotes);
        $this->assertFalse($quotes[0]->serviceable);
        $this->assertSame(0.0, $quotes[0]->cost);
        $this->assertSame('Rider not Available currently!', $quotes[0]->unserviceableReason);
    }

    public function test_mixed_or_malformed_unavailable_shape_fails_closed(): void
    {
        $this->fakeSettings(['delivery_uengage_api_key' => 'secret', 'delivery_uengage_store_id' => '69840']);
        Http::fake(['*/getServiceability' => Http::response([
            'status' => '400',
            'serviceability' => ['riderServiceAble' => false, 'locationServiceAble' => true],
            'payouts' => ['message' => 'Unknown partial state'],
        ])]);

        $this->expectException(ProviderUnavailable::class);
        app(UengageDeliveryProvider::class)->quotes(
            new DeliveryLocation(22.94, 76.04), new DeliveryLocation(22.95, 76.05), 'ORD-MALFORMED', false,
        );
    }

    public function test_production_host_requires_separate_server_side_gate(): void
    {
        config(['delivery.uengage.environment' => 'production']);
        Http::fake();
        $this->expectException(ProviderUnavailable::class);
        try {
            app(UengageClient::class)->trackTaskStatus('secret', '89', 'TASK');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_track_cancel_and_create_task_follow_documented_contract_without_retries(): void
    {
        Http::fake([
            '*/trackTaskStatus' => Http::response(['status' => true, 'status_code' => 'ALLOTTED', 'data' => ['taskId' => 'TASK']]),
            '*/cancelTask' => Http::response(['status' => true, 'status_code' => 'CANCELLED', 'message' => 'Order has been cancelled']),
            '*/createTask' => Http::response(['status' => true, 'taskId' => 'TASK', 'Status_code' => 'ACCEPTED']),
        ]);
        $client = app(UengageClient::class);
        $client->trackTaskStatus('secret', '89', 'TASK');
        $client->cancelTask('secret', '89', 'TASK');
        $client->createTask('secret', $this->taskPayload());

        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => ! $request->hasHeader('Idempotency-Key'));
    }

    public function test_all_documented_statuses_are_explicit_and_unknown_status_fails_closed(): void
    {
        $expected = [
            'ACCEPTED' => DeliveryStatus::RiderSearching,
            'ALLOTTED' => DeliveryStatus::RiderAssigned,
            'ARRIVED' => DeliveryStatus::ArrivedAtPickup,
            'DISPATCHED' => DeliveryStatus::PickedUp,
            'ARRIVED_CUSTOMER_DOORSTEP' => DeliveryStatus::ArrivedAtCustomer,
            'DELIVERED' => DeliveryStatus::Delivered,
            'RTO_INIT' => DeliveryStatus::Rto,
            'RTO_COMPLETE' => DeliveryStatus::RtoCompleted,
            'CANCELLED' => DeliveryStatus::Cancelled,
            'SEARCHING_FOR_NEW_RIDER' => DeliveryStatus::RiderSearching,
            'RETURNED_AFTER_DELIVERY' => DeliveryStatus::ReturnedAfterDelivery,
        ];
        foreach ($expected as $official => $canonical) {
            $this->assertSame($canonical, UengageStatus::normalize($official));
        }
        $this->assertNull(UengageStatus::normalize('FUTURE_STATUS'));
        $this->assertNull(UengageStatus::normalize(''));
        $this->assertNull(UengageStatus::normalize(null));
        $this->assertNull(UengageStatus::normalize(123));
        $this->assertNull(UengageStatus::normalize(str_repeat('X', 81)));
    }

    public function test_authentication_4xx_5xx_and_malformed_responses_are_classified(): void
    {
        Http::fakeSequence()
            ->push(['message' => 'Invalid Token'], 400)
            ->push(['message' => 'bad request'], 422)
            ->push(['message' => 'down'], 503)
            ->push('not-json', 200);
        foreach ([
            'PROVIDER_AUTHENTICATION_ERROR', 'PROVIDER_4XX', 'PROVIDER_5XX', 'PROVIDER_INVALID_RESPONSE',
        ] as $expected) {
            try {
                app(UengageClient::class)->trackTaskStatus('secret', '89', 'TASK');
                $this->fail("Expected {$expected}.");
            } catch (ProviderUnavailable $exception) {
                $this->assertSame($expected, $exception->reasonCode);
            }
        }
    }

    public function test_timeout_is_ambiguous_and_never_retried(): void
    {
        Http::fake(['*/createTask' => Http::failedConnection('Operation timed out')]);
        try {
            app(UengageClient::class)->createTask('secret', $this->taskPayload());
            $this->fail('Timeout was treated as a known booking failure.');
        } catch (ProviderUnavailable $exception) {
            $this->assertSame('PROVIDER_TIMEOUT', $exception->reasonCode);
        }
        Http::assertSentCount(1);
    }

    public function test_operation_specific_malformed_successes_fail_closed(): void
    {
        Http::fakeSequence()->push(['status' => true], 200)->push(['status' => true], 200)->push(['status' => true], 200);
        foreach (['track', 'cancel', 'create'] as $operation) {
            try {
                match ($operation) {
                    'track' => app(UengageClient::class)->trackTaskStatus('secret', '89', 'TASK'),
                    'cancel' => app(UengageClient::class)->cancelTask('secret', '89', 'TASK'),
                    'create' => app(UengageClient::class)->createTask('secret', $this->taskPayload()),
                };
                $this->fail("Malformed {$operation} response was accepted.");
            } catch (ProviderUnavailable $exception) {
                $this->assertSame('PROVIDER_INVALID_RESPONSE', $exception->reasonCode);
            }
        }
    }

    public function test_create_task_rejects_wrong_and_unbounded_identifiers(): void
    {
        foreach ([
            ['status' => true, 'taskId' => 123, 'Status_code' => 'ACCEPTED'],
            ['status' => true, 'taskId' => str_repeat('x', 192), 'Status_code' => 'ACCEPTED'],
            ['status' => true, 'taskId' => 'TASK', 'Status_code' => str_repeat('x', 81)],
        ] as $body) {
            Http::fake(['*/createTask' => Http::response($body)]);
            try {
                app(UengageClient::class)->createTask('secret', $this->taskPayload());
                $this->fail('Unsafe provider identifier was accepted.');
            } catch (ProviderUnavailable $exception) {
                $this->assertSame('PROVIDER_INVALID_RESPONSE', $exception->reasonCode);
            }
        }
    }

    public function test_serviceability_rejects_negative_and_non_finite_costs(): void
    {
        $this->fakeSettings(['delivery_uengage_api_key' => 'secret', 'delivery_uengage_store_id' => '89']);
        foreach ([-1, 'INF'] as $cost) {
            Http::fake(['*/getServiceability' => Http::response([
                'status' => '200',
                'serviceability' => ['riderServiceAble' => true, 'locationServiceAble' => true],
                'payouts' => ['total' => $cost],
            ])]);
            try {
                app(UengageDeliveryProvider::class)->quotes(
                    new DeliveryLocation(28, 77), new DeliveryLocation(28.1, 77.1), 'ORD-1', false,
                );
                $this->fail('Invalid provider cost was accepted.');
            } catch (ProviderUnavailable $exception) {
                $this->assertSame('PROVIDER_INVALID_RESPONSE', $exception->reasonCode);
            }
        }
    }

    public function test_redirect_is_not_followed_or_treated_as_success(): void
    {
        Http::fake(['*/trackTaskStatus' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal'])]);
        try {
            app(UengageClient::class)->trackTaskStatus('secret', '89', 'TASK');
            $this->fail('Redirect was followed or accepted.');
        } catch (ProviderUnavailable $exception) {
            $this->assertSame('UNKNOWN_PROVIDER_RESULT', $exception->reasonCode);
        }
        Http::assertSentCount(1);
    }

    public function test_parent_feature_gates_cannot_be_bypassed_by_child_gates(): void
    {
        Http::fake(['*/createTask' => Http::response(['status' => true, 'taskId' => 'TASK', 'Status_code' => 'ACCEPTED'])]);
        foreach ([
            ['delivery.integration_enabled' => false, 'delivery.sandbox_enabled' => true, 'delivery.booking_enabled' => true],
            ['delivery.integration_enabled' => true, 'delivery.sandbox_enabled' => false, 'delivery.booking_enabled' => true],
            ['delivery.integration_enabled' => true, 'delivery.sandbox_enabled' => true, 'delivery.booking_enabled' => false],
        ] as $flags) {
            config($flags + ['delivery.uengage.environment' => 'sandbox']);
            try {
                app(UengageClient::class)->createTask('secret', $this->taskPayload());
                $this->fail('Incomplete feature-gate combination reached the provider boundary.');
            } catch (ProviderUnavailable) {
                // Expected: every combination is blocked before HTTP.
            }
        }
        Http::assertNothingSent();
        $this->assertFalse((bool) config('delivery.automatic_create_task_retry_enabled'));
    }

    private function fakeSettings(array $values): void
    {
        $credentials = \Mockery::mock(\Modules\Order\Delivery\PlatformDeliveryCredentials::class);
        $credentials->shouldReceive('apiKey')->andReturn((string) ($values['delivery_uengage_api_key'] ?? ''));
        $credentials->shouldReceive('storeId')->andReturn((string) ($values['delivery_uengage_store_id'] ?? ''));
        $this->app->instance(\Modules\Order\Delivery\PlatformDeliveryCredentials::class, $credentials);

        app()->instance('setting', new class($values) {
            public function __construct(private readonly array $values) {}
            public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
        });
    }

    private function taskPayload(): array
    {
        return [
            'storeId' => '89',
            'order_details' => ['order_total' => 100, 'paid' => 'true', 'vendor_order_id' => 'ORD-1', 'order_source' => 'website'],
            'pickup_details' => ['name' => 'Outlet', 'contact_number' => '1000000000', 'latitude' => 28, 'longitude' => 77, 'address' => 'Test', 'city' => 'Test'],
            'drop_details' => ['name' => 'Customer', 'contact_number' => '1000000001', 'latitude' => 28.1, 'longitude' => 77.1, 'address' => 'Test', 'city' => 'Test'],
        ];
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function liveAuthFailureShapes(): array
    {
        // Captured from open-api.flash.uengage.in on 2026-09-22: HTTP 200 bodies.
        return [
            'serviceability bad token or store' => [['status' => '400', 'msg' => 'Invalid Auth token or Store ID']],
            'missing token header' => [['status' => '400', 'msg' => 'Invalid Auth token']],
            'track task bad token' => [['message' => 'Invalid Token', 'status' => '400']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('liveAuthFailureShapes')]
    public function test_http_200_auth_failures_are_reported_as_credential_errors(array $body): void
    {
        Http::fake(['*/getServiceability' => Http::response($body, 200)]);

        try {
            app(UengageClient::class)->serviceability('bad', '89', new DeliveryLocation(28.39, 77.34), new DeliveryLocation(28.40, 77.31));
            $this->fail('An authentication failure must not be returned as a serviceability body.');
        } catch (ProviderUnavailable $exception) {
            $this->assertSame('PROVIDER_AUTHENTICATION_ERROR', $exception->reasonCode);
        }
    }

    public function test_validation_error_object_message_does_not_crash_and_is_not_an_auth_error(): void
    {
        Http::fake(['*/getServiceability' => Http::response(['status' => 0, 'msg' => ['store_id' => 'The store_id field is required.']], 200)]);

        $body = app(UengageClient::class)->serviceability('t', '', new DeliveryLocation(28.39, 77.34), new DeliveryLocation(28.40, 77.31));

        $this->assertSame(0, $body['status']);
    }
}

<?php

namespace Tests\Unit\Order;

use Illuminate\Contracts\Translation\Translator;
use Mockery;
use Modules\Aggregator\Models\PartnerApiOrderMapping;
use Modules\Order\Models\Order;
use Modules\Order\Support\OrderSourcePresenter;
use Modules\WhatsAppCenter\Models\WhatsAppOrderSession;
use Tests\TestCase;

class OrderSourcePresenterTest extends TestCase
{
    public function test_loaded_partner_mapping_takes_precedence_over_fulfilment_type(): void
    {
        $translator = Mockery::mock(Translator::class);
        $translator->shouldReceive('get')->andReturn('Partner');
        $this->app->instance('translator', $translator);

        $order = (new Order)->forceFill([
            'type' => 'dine_in',
            'created_by' => 10,
        ]);
        $order->setRelation('partnerApiOrderMapping', new PartnerApiOrderMapping([
            'uuid' => '4cb166d6-e93b-4c42-a067-3fc547846c38',
        ]));

        $source = OrderSourcePresenter::make($order);

        $this->assertSame('partner', $source['source_type']);
        $this->assertSame('Partner', $source['source_label']);
        $this->assertNull($source['aggregator_provider']);
    }

    public function test_loaded_whatsapp_session_identifies_whatsapp_source(): void
    {
        $translator = Mockery::mock(Translator::class);
        $translator->shouldReceive('get')->andReturn('WhatsApp');
        $this->app->instance('translator', $translator);

        $order = (new Order)->forceFill(['type' => 'takeaway', 'created_by' => null]);
        $order->setRelation('whatsAppOrderSession', new WhatsAppOrderSession(['uuid' => 'session-id']));

        $source = OrderSourcePresenter::make($order);

        $this->assertSame('whatsapp', $source['source_type']);
        $this->assertSame('WhatsApp', $source['source_label']);
        $this->assertNull($source['aggregator_provider']);
    }

    public function test_explicit_qr_fulfilment_source_identifies_qr_order(): void
    {
        $translator = Mockery::mock(Translator::class);
        $translator->shouldReceive('get')->andReturnUsing(fn ($key) => match ($key) {
            'order::orders.sources.qr' => 'QR Order',
            default => $key,
        });
        $this->app->instance('translator', $translator);

        $order = (new Order)->forceFill([
            'type' => 'dine_in',
            'created_by' => 10,
            'customer_id' => 10,
            'table_id' => 12,
            'fulfilment' => ['source' => 'qr'],
        ]);

        $source = OrderSourcePresenter::make($order);

        $this->assertSame('qr', $source['source_type']);
        $this->assertSame('QR Order', $source['source_label']);
    }

    public function test_legacy_customer_table_order_is_inferred_as_qr_order(): void
    {
        $translator = Mockery::mock(Translator::class);
        $translator->shouldReceive('get')->andReturnUsing(fn ($key) => match ($key) {
            'order::orders.sources.qr' => 'QR Order',
            default => $key,
        });
        $this->app->instance('translator', $translator);

        $order = (new Order)->forceFill([
            'type' => 'dine_in',
            'created_by' => 44,
            'customer_id' => 44,
            'table_id' => 12,
            'pos_register_id' => null,
            'pos_session_id' => null,
        ]);

        $source = OrderSourcePresenter::make($order);

        $this->assertSame('qr', $source['source_type']);
        $this->assertSame('QR Order', $source['source_label']);
    }


    public function test_explicit_whatsapp_fulfilment_source_identifies_whatsapp_order_before_relation_loads(): void
    {
        $translator = Mockery::mock(Translator::class);
        $translator->shouldReceive('get')->andReturnUsing(fn ($key) => match ($key) {
            'order::orders.sources.whatsapp' => 'WhatsApp',
            default => $key,
        });
        $this->app->instance('translator', $translator);

        $order = (new Order)->forceFill([
            'type' => 'takeaway',
            'created_by' => 44,
            'customer_id' => 44,
            'fulfilment' => ['source' => 'whatsapp', 'channel' => 'whatsapp_catalog'],
        ]);

        $source = OrderSourcePresenter::make($order);

        $this->assertSame('whatsapp', $source['source_type']);
        $this->assertSame('WhatsApp', $source['source_label']);
    }

    public function test_waiter_created_order_is_identified_as_waiter_app(): void
    {
        $translator = Mockery::mock(Translator::class);
        $translator->shouldReceive('get')->andReturnUsing(fn ($key) => match ($key) {
            'order::orders.sources.waiter_app' => 'Waiter App',
            default => $key,
        });
        $this->app->instance('translator', $translator);

        $order = (new Order)->forceFill([
            'type' => 'dine_in',
            'created_by' => 7,
            'waiter_id' => 7,
            'table_id' => 12,
        ]);

        $source = OrderSourcePresenter::make($order);

        $this->assertSame('waiter_app', $source['source_type']);
        $this->assertSame('Waiter App', $source['source_label']);
    }

}

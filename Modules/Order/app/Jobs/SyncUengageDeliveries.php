<?php

namespace Modules\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Modules\Order\Delivery\PlatformDeliveryCredentials;
use Modules\Order\Delivery\DeliveryStateMachine;
use Modules\Order\Delivery\ProviderUnavailable;
use Modules\Order\Delivery\UengageClient;
use Modules\Order\Delivery\UengageWebhookProcessor;
use Modules\Order\Enums\DeliveryStatus;
use Modules\Order\Models\OrderDelivery;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Throwable;

final class SyncUengageDeliveries implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 50;

    public function __construct()
    {
        $this->onQueue((string) config('delivery.queue', 'delivery'));
    }

    public function handle(UengageClient $client, UengageWebhookProcessor $processor, DeliveryStateMachine $stateMachine): void
    {
        if (! config('delivery.integration_enabled', false)) return;

        $deliveries = OrderDelivery::query()->withoutGlobalScopes()
            ->where('provider', 'uengage')->whereNotNull('external_delivery_id')
            ->whereIn('status', [
                DeliveryStatus::RiderSearching->value,
                DeliveryStatus::RiderAssigned->value,
                DeliveryStatus::ArrivedAtPickup->value,
                DeliveryStatus::PickedUp->value,
                DeliveryStatus::InTransit->value,
                DeliveryStatus::ArrivedAtCustomer->value,
            ])->where(function ($query): void {
                $query->whereNull('updated_at')
                    ->orWhere('updated_at', '<=', now()->subSeconds(45));
            })->oldest('updated_at')->limit(100)->get();

        foreach ($deliveries as $delivery) {
            try {
                app(TenantContext::class)->setId((int) $delivery->tenant_id);
                app(SettingServiceInterface::class)->refreshSettingBinding();
                $credentials = app(PlatformDeliveryCredentials::class);
                $body = $client->trackTaskStatus(
                    (string) $credentials->apiKey(),
                    (string) $credentials->storeId(),
                    (string) $delivery->external_delivery_id,
                );
                $processor->processTrusted($body);
                Cache::forget('delivery:uengage-sync-failures:'.$delivery->id);
            } catch (Throwable $exception) {
                if ($exception instanceof ProviderUnavailable) {
                    $failureKey = 'delivery:uengage-sync-failures:'.$delivery->id;
                    Cache::add($failureKey, 0, now()->addMinutes(20));
                    $failures = (int) Cache::increment($failureKey);
                    if ($failures >= 3 && $delivery->updated_at?->lte(now()->subMinutes(3))) {
                        DB::transaction(function () use ($delivery, $stateMachine, $exception): void {
                            $locked = OrderDelivery::query()->withoutGlobalScopes()->whereKey($delivery->id)->lockForUpdate()->first();
                            if (! $locked || ! in_array($locked->status, [
                                DeliveryStatus::RiderSearching,
                                DeliveryStatus::RiderAssigned,
                                DeliveryStatus::ArrivedAtPickup,
                                DeliveryStatus::PickedUp,
                                DeliveryStatus::InTransit,
                                DeliveryStatus::ArrivedAtCustomer,
                            ], true)) return;
                            $stateMachine->transition($locked, DeliveryStatus::Investigation, [
                                'assignment_status' => 'provider_reconciliation_required',
                                'failure_code' => $exception->reasonCode,
                                'failure_reason' => 'Repeated provider polling failures require manual reconciliation. No new delivery task may be created until this task is verified.',
                            ]);
                        }, 3);
                        Cache::forget($failureKey);
                    }
                }
                $warningKey = 'delivery:uengage-sync-warning:'.$delivery->id.':'.sha1($exception::class.'|'.$exception->getMessage());
                if (Cache::add($warningKey, true, now()->addMinutes(15))) {
                    Log::warning('Unable to synchronize uEngage delivery status.', [
                        'delivery_id' => $delivery->id,
                        'tenant_id' => $delivery->tenant_id,
                        'exception_type' => $exception::class,
                        'exception_message' => mb_substr($exception->getMessage(), 0, 300),
                    ]);
                }
            } finally {
                app(TenantContext::class)->setId(null);
                app(SettingServiceInterface::class)->refreshSettingBinding();
            }
        }
    }
}

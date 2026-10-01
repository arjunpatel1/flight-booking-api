<?php

namespace Modules\Saas\Broadcasting;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Modules\Saas\Support\RealtimeChannelTelemetry;
use Modules\Saas\Support\TenantChannelNaming;

/**
 * Decorates the real (Reverb) broadcaster to emit each event on BOTH its
 * current v1 channel and its tenant-namespaced v2 channel — simultaneously —
 * so old and new clients both receive it during the migration window.
 *
 *   event → [pos.orders.branch.6]
 *         → broadcast to  pos.orders.branch.6            (v1, existing clients)
 *                    and  pos.tenant.9.branch.6          (v2, migrated clients)
 *
 * It touches NO event class: interception happens at the broadcaster boundary,
 * where channel names are already just strings. `auth()` and
 * `validAuthenticationResponse()` delegate untouched, so authentication is
 * unchanged.
 *
 * Fail-safe by construction: if a v2 name cannot be derived (tenant
 * unresolvable), only the v1 channel is used. A migration helper must never be
 * able to drop a real-time message.
 *
 * Active only when REALTIME_CHANNEL_VERSION=v2 (the provider points the default
 * broadcast connection here). At v1 this class is never instantiated.
 */
class DualChannelBroadcaster implements Broadcaster
{
    public function __construct(
        private readonly Broadcaster $inner,
        private readonly TenantChannelNaming $naming,
        private readonly RealtimeChannelTelemetry $telemetry,
    ) {
    }

    public function auth($request)
    {
        return $this->inner->auth($request);
    }

    public function validAuthenticationResponse($request, $result)
    {
        return $this->inner->validAuthenticationResponse($request, $result);
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $expanded = [];
        $v1 = 0;
        $v2 = 0;

        foreach ($channels as $channel) {
            $name = (string) $channel;
            $expanded[$name] = true;

            if ($this->naming->isV2($name)) {
                $v2++;
                continue;
            }

            $v1++;
            $sibling = $this->naming->toV2($name);
            if ($sibling !== null) {
                $expanded[$sibling] = true;
                $v2++;
            }
        }

        $this->telemetry->recordBroadcast(TenantChannelNaming::VERSION_V1, $v1);
        $this->telemetry->recordBroadcast(TenantChannelNaming::VERSION_V2, $v2);

        return $this->inner->broadcast(array_keys($expanded), $event, $payload);
    }
}

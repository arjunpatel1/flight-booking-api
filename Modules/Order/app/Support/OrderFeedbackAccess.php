<?php

namespace Modules\Order\Support;

use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Order;

final class OrderFeedbackAccess
{
    public static function token(Order $order): string
    {
        // Orders are branch-owned and do not carry a tenant_id column. Resolve
        // tenant ownership from the already-loaded branch where possible, with
        // a strict branch lookup for partially-selected order models.
        $order->loadMissing('branch');
        $tenantId = $order->branch?->tenant_id
            ?? DB::table('branches')->where('id', $order->branch_id)->value('tenant_id');

        return hash_hmac(
            'sha256',
            implode('|', [(string) $tenantId, (string) $order->reference_no, 'customer-feedback']),
            (string) config('app.key'),
        );
    }

    public static function valid(Order $order, mixed $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals(self::token($order), $token);
    }
}

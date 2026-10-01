<?php

namespace Modules\Notification\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Core\Http\Controllers\Controller;
use Modules\Notification\Models\TenantMailMessage;
use Modules\Notification\Services\MailgunWebhookVerifier;
use Modules\Saas\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

class MailgunEventController extends Controller
{
    public function __invoke(Request $request, MailgunWebhookVerifier $verifier): JsonResponse
    {
        $verifier->verify($request, 'event');
        $event = Str::lower((string) $request->input('event-data.event'));
        $providerId = trim((string) $request->input('event-data.message.headers.message-id'), '<> ');
        $sender = $this->email((string) ($request->input('event-data.envelope.sender') ?: $request->input('event-data.message.headers.from')));
        abort_if($event === '' || $providerId === '' || ! $sender, Response::HTTP_UNPROCESSABLE_ENTITY);
        [$slug, $domain] = array_pad(explode('@', $sender, 2), 2, null);
        abort_unless(hash_equals(Str::lower(config('services.mailgun.inbound_domain', 'myteknoland.in')), Str::lower((string) $domain)), Response::HTTP_NOT_FOUND);
        $tenantId = Tenant::query()->withoutGlobalScopes()->where('slug', $slug)->value('id');
        abort_if(! $tenantId, Response::HTTP_NOT_FOUND);

        $status = match ($event) {
            'accepted' => 'accepted',
            'delivered' => 'delivered',
            'failed', 'rejected' => 'failed',
            default => null,
        };
        if ($status) {
            TenantMailMessage::query()->withoutGlobalTenant()
                ->where('tenant_id', $tenantId)->where('provider_message_id', $providerId)
                ->update(['status' => $status]);
        }

        return response()->json(['accepted' => true]);
    }

    private function email(string $value): ?string
    {
        if (preg_match('/<([^>]+)>/', $value, $matches)) $value = $matches[1];
        $value = Str::lower(trim($value));

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }
}

<?php

namespace Modules\Notification\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Http\Controllers\Controller;
use Modules\Notification\Models\TenantMailThread;
use Modules\Notification\Services\TenantMailboxAttachmentService;
use Modules\Notification\Services\MailgunWebhookVerifier;
use Modules\Saas\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

class MailgunInboundController extends Controller
{
    public function __invoke(Request $request, TenantMailboxAttachmentService $attachments, MailgunWebhookVerifier $verifier): JsonResponse
    {
        $verifier->verify($request, 'inbound');

        $recipient = $this->email((string) $request->input('recipient'));
        $sender = $this->email((string) $request->input('sender'));
        abort_if(!$recipient || !$sender, Response::HTTP_UNPROCESSABLE_ENTITY);
        [$slug, $domain] = array_pad(explode('@', $recipient, 2), 2, null);
        abort_unless(hash_equals(Str::lower(config('services.mailgun.inbound_domain', 'myteknoland.in')), Str::lower((string) $domain)), Response::HTTP_NOT_FOUND);
        $tenant = Tenant::query()->withoutGlobalScopes()->where('slug', $slug)->firstOrFail();
        $subject = Str::limit(trim((string) $request->input('subject', '(No subject)')), 255, '');
        $providerId = Str::limit(trim((string) $request->input('Message-Id', $request->input('message-id'))), 255, '');
        $body = Str::limit(trim(strip_tags((string) $request->input('body-plain'))), 50000, '');

        $message = DB::transaction(function () use ($tenant, $sender, $recipient, $subject, $providerId, $body) {
            $thread = TenantMailThread::query()->withoutGlobalTenant()->firstOrCreate([
                'tenant_id' => $tenant->id,
                'subject' => preg_replace('/^\s*re:\s*/i', '', $subject),
                'participant_email' => $sender,
            ], [
                'reference' => (string) Str::uuid(),
                'last_message_at' => now(),
            ]);
            $thread->update(['last_message_at' => now(), 'read_at' => null]);
            $attributes = [
                'tenant_id' => $tenant->id,
                'provider_message_id' => $providerId ?: null,
            ];
            $values = [
                'direction' => 'inbound',
                'from_address' => $sender,
                'to_address' => $recipient,
                'body_text' => $body,
                'status' => 'received',
                'received_at' => now(),
            ];
            if ($providerId !== '') {
                return $thread->messages()->firstOrCreate($attributes, $values);
            }

            return $thread->messages()->create($attributes + $values);
        });

        if ($message->wasRecentlyCreated || $providerId === '') {
            collect($request->allFiles())->flatten()->take(5)->each(
                fn ($file) => $attachments->store($message, $file),
            );
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

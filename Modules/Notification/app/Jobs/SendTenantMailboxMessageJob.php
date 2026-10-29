<?php

namespace Modules\Notification\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Modules\Notification\Models\TenantMailMessage;
use Modules\Notification\Services\Channels\EmailChannel;
use Modules\Notification\Services\TenantMailerService;
use Modules\Saas\Models\Tenant;

class SendTenantMailboxMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(public readonly int $tenantId, public readonly int $messageId)
    {
        $this->onQueue('mail');
    }

    public function handle(EmailChannel $renderer, TenantMailerService $mailers): void
    {
        $message = TenantMailMessage::query()->withoutGlobalTenant()
            ->where('tenant_id', $this->tenantId)->findOrFail($this->messageId);
        $thread = $message->thread()->withoutGlobalTenant()->firstOrFail();
        $attachments = $message->attachments()->withoutGlobalTenant()->where('status', 'accepted')->get();

        $bodyHtml = $renderer->renderLayout(
            $thread->subject,
            (string) ($message->body_text ?? ''),
            (string) ($message->body_html ?? ''),
            (string) config('saas.company_mail.brand_name', config('app.name', 'NexDine')),
            (string) (config('saas.company_mail.logo_url') ?: rtrim((string) config('app.url'), '/').'/logo.svg'),
            (string) config('saas.company_mail.primary_color', '#F57C00'),
        );
        // The tenant's own server when connected, otherwise the platform's Mailgun.
        // Replies are pointed back at the Mailgun inbox so they still thread here.
        $own = $mailers->resolve($this->tenantId);
        $inboxAddress = $own
            ? Tenant::query()->withoutGlobalScopes()->whereKey($this->tenantId)->value('slug')
                .'@'.config('services.mailgun.inbound_domain', 'myteknoland.in')
            : null;

        $sent = ($own['mailer'] ?? Mail::mailer('mailgun'))->html(
            $bodyHtml,
            function ($mail) use ($message, $thread, $attachments, $own, $inboxAddress) {
                $mail->from($message->from_address, ($own['from_name'] ?? '') ?: config('mail.from.name'))
                    ->to($message->to_address)
                    ->subject($thread->subject);
                if ($inboxAddress) {
                    $mail->replyTo($inboxAddress);
                }
                foreach ($attachments as $attachment) {
                    $mail->attachFromStorageDisk(
                        $attachment->disk,
                        $attachment->path,
                        $attachment->original_name,
                        ['mime' => $attachment->mime_type],
                    );
                }
            },
        );

        $message->update([
            'status' => 'sent',
            'sent_at' => now(),
            // Only Mailgun ids can be checked by the delivery-status sync.
            'provider_message_id' => $sent && ! $own ? trim((string) $sent->getMessageId(), '<> ') : null,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        TenantMailMessage::query()->withoutGlobalTenant()
            ->where('tenant_id', $this->tenantId)->whereKey($this->messageId)
            ->update(['status' => 'failed']);
    }
}

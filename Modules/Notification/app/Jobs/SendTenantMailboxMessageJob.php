<?php

namespace Modules\Notification\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Modules\Notification\Models\TenantMailMessage;
use Modules\Notification\Services\Channels\EmailChannel;

class SendTenantMailboxMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(public readonly int $tenantId, public readonly int $messageId)
    {
        $this->onQueue('mail');
    }

    public function handle(EmailChannel $renderer): void
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
        $sent = Mail::mailer('mailgun')->html(
            $bodyHtml,
            function ($mail) use ($message, $thread, $attachments) {
                $mail->from($message->from_address, config('mail.from.name'))
                    ->to($message->to_address)
                    ->subject($thread->subject);
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
            'provider_message_id' => $sent ? trim((string) $sent->getMessageId(), '<> ') : null,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        TenantMailMessage::query()->withoutGlobalTenant()
            ->where('tenant_id', $this->tenantId)->whereKey($this->messageId)
            ->update(['status' => 'failed']);
    }
}

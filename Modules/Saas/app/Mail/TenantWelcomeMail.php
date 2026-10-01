<?php

namespace Modules\Saas\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Saas\Models\Tenant;

/**
 * Module 6 — the welcome email a restaurant owner receives on activation.
 *
 * Only dispatched when `saas.workspace.welcome_notifications.email_enabled` is
 * true; see TenantWelcomeNotifier.
 *
 * It deliberately carries no password. Credentials are set by the owner through
 * the normal password-reset flow, so an intercepted or forwarded welcome email
 * never hands over the account.
 */
class TenantWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly array $resources = [],
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('saas::workspace.welcome.subject', ['restaurant' => $this->tenant->name]),
        );
    }

    public function content(): Content
    {
        $adminUrl = filled($this->tenant->domain)
            ? 'https://' . ltrim((string) $this->tenant->domain, 'https://')
            : rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            view: 'saas::emails.tenant-welcome',
            with: [
                'tenant' => $this->tenant,
                'adminUrl' => $adminUrl,
                'artifacts' => collect($this->resources['artifacts'] ?? [])
                    ->filter(fn (array $artifact) => $artifact['is_downloadable'] ?? false)
                    ->values()
                    ->all(),
                'support' => $this->resources['support'] ?? [],
            ],
        );
    }
}

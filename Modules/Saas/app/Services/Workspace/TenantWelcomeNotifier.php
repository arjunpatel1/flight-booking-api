<?php

namespace Modules\Saas\Services\Workspace;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Notification\Services\Channels\WhatsAppChannel;
use Modules\Saas\Mail\TenantWelcomeMail;
use Modules\Saas\Models\Tenant;
use Throwable;

/**
 * Modules 6 and 7 — the welcome email and WhatsApp a restaurant owner receives
 * once their restaurant goes live.
 *
 * DISABLED BY DEFAULT. Both channels are gated on
 * `saas.workspace.welcome_notifications.*`, which default to false, because
 * enabling them sends real messages to real restaurant owners. Turn them on
 * only once the templates and the sender identity are approved:
 *
 *     SAAS_WELCOME_EMAIL_ENABLED=true
 *     SAAS_WELCOME_WHATSAPP_ENABLED=true
 *
 * Delivery failure is logged and swallowed. A welcome message that fails must
 * never roll back an activation that otherwise succeeded — the owner can still
 * log in and self-serve from the workspace.
 */
class TenantWelcomeNotifier
{
    public function __construct(private readonly TenantWorkspaceService $workspace)
    {
    }

    public function send(Tenant $tenant): void
    {
        $this->sendEmail($tenant);
        $this->sendWhatsApp($tenant);
    }

    public function emailEnabled(): bool
    {
        return (bool) config('saas.workspace.welcome_notifications.email_enabled', false);
    }

    public function whatsAppEnabled(): bool
    {
        return (bool) config('saas.workspace.welcome_notifications.whatsapp_enabled', false);
    }

    private function sendEmail(Tenant $tenant): void
    {
        if (! $this->emailEnabled() || blank($tenant->contact_email)) {
            return;
        }

        try {
            Mail::to($tenant->contact_email)->send(new TenantWelcomeMail(
                tenant: $tenant,
                resources: $this->workspace->resources($tenant),
            ));
        } catch (Throwable $exception) {
            Log::warning('Tenant welcome email could not be delivered.', [
                'tenant_id' => $tenant->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Sent through the existing WhatsApp channel, which posts an approved
     * *template* rather than free text — business-initiated WhatsApp messages
     * require one. The template named below must exist and be approved at the
     * provider before this is switched on; see
     * `config('saas.workspace.welcome_notifications')`.
     */
    public const WHATSAPP_TEMPLATE = 'tenant_welcome';

    private function sendWhatsApp(Tenant $tenant): void
    {
        if (! $this->whatsAppEnabled() || blank($tenant->contact_phone)) {
            return;
        }

        try {
            app(WhatsAppChannel::class)->send($tenant->contact_phone, [
                'template' => self::WHATSAPP_TEMPLATE,
                'parameters' => $this->whatsAppParameters($tenant),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Tenant welcome WhatsApp could not be delivered.', [
                'tenant_id' => $tenant->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Ordered template parameters. Keep this order in step with the approved
     * template body — WhatsApp substitutes positionally, so a reorder here
     * silently produces a scrambled message.
     *
     * 1 restaurant name, 2 admin URL, 3 get-started URL,
     * 4 support contact, 5 business hours.
     */
    public function whatsAppParameters(Tenant $tenant): array
    {
        $support = (array) config('saas.workspace.support', []);
        $adminUrl = $this->adminUrl($tenant);

        return [
            $tenant->name,
            $adminUrl,
            // Short link, per the documented onboarding URL. Aliased to
            // /admin/get-started in the SPA router.
            "{$adminUrl}/get-started",
            (string) ($support['phone'] ?? $support['email'] ?? ''),
            (string) ($support['business_hours'] ?? ''),
        ];
    }

    public function adminUrl(Tenant $tenant): string
    {
        return filled($tenant->domain)
            ? 'https://' . ltrim((string) $tenant->domain, 'https://')
            : rtrim((string) config('app.frontend_url', config('app.url')), '/');
    }
}

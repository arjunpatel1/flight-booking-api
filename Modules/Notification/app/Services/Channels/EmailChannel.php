<?php

namespace Modules\Notification\Services\Channels;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class EmailChannel implements NotificationChannelInterface
{
    /**
     * Deliver a notification by email through the configured mailer.
     *
     * Uses the framework Mail facade (from address, transport and driver all come
     * from config/mail.php), so it honours whatever MAIL_MAILER the environment
     * sets. The dispatcher wraps this call in a try/catch and records the result
     * on the notification log, so a transport failure never breaks the loop.
     */
    public function send(string $recipient, array $payload): array
    {
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return [
                'sent' => false,
                'channel' => 'email',
                'recipient' => $recipient,
                'error' => 'invalid_recipient',
            ];
        }

        $subject = (string) ($payload['title'] ?? $payload['subject'] ?? config('app.name'));
        $body = (string) ($payload['message'] ?? $payload['body'] ?? '');
        $restaurantName = trim((string) ($payload['brand_name'] ?? $payload['restaurant_name'] ?? setting('app_name') ?? config('app.name')));
        $logoUrl = trim((string) ($payload['logo_url'] ?? setting('restaurant_logo_url') ?? ''));
        $primaryColor = $this->safeColor((string) ($payload['primary_color'] ?? setting('appearance_primary_color') ?? '#F57C00'));
        $requestedFrom = strtolower(trim((string) ($payload['from_address'] ?? '')));
        $allowedSenders = collect(config('saas.company_mail.senders', []))
            ->filter(fn ($sender) => is_array($sender) && filter_var($sender['address'] ?? null, FILTER_VALIDATE_EMAIL))
            ->keyBy(fn ($sender) => strtolower((string) $sender['address']));
        $from = $requestedFrom !== '' ? $allowedSenders->get($requestedFrom) : null;

        $bodyHtml = trim((string) ($payload['message_html'] ?? ''));
        $otpCode = trim((string) ($payload['otp_code'] ?? ''));
        $attachments = collect($payload['attachments'] ?? [])->filter(fn ($item) => is_array($item) && filled($item['path'] ?? null));
        $html = $this->renderLayout($subject, $body, $bodyHtml, $restaurantName, $logoUrl, $primaryColor, $otpCode, (string) ($payload['setup_url'] ?? ''));
        Mail::html($html, function ($message) use ($recipient, $subject, $from, $attachments) {
            $message->to($recipient)->subject($subject);
            if ($from) {
                $message->from($from['address'], $from['name'] ?? null);
            }
            foreach ($attachments as $attachment) {
                $disk = (string) ($attachment['disk'] ?? 'local');
                if (! Storage::disk($disk)->exists($attachment['path'])) continue;
                $message->attachData(
                    Storage::disk($disk)->get($attachment['path']),
                    basename((string) ($attachment['name'] ?? 'attachment')),
                    ['mime' => $attachment['mime'] ?? 'application/octet-stream'],
                );
            }
        });

        return [
            'sent' => true,
            'channel' => 'email',
            'recipient' => $recipient,
        ];
    }

    /**
     * Wrap restaurant-controlled plain text in a safe, responsive mail shell.
     * Template content remains escaped; only verified http(s) URLs become links.
     */
    public function renderLayout(string $subject, string $body, string $bodyHtml, string $restaurantName, string $logoUrl, string $primaryColor, string $otpCode = '', string $actionUrl = ''): string
    {
        $safeName = e($restaurantName ?: config('app.name'));
        $safeSubject = e($subject);
        $bodyHtml = $this->sanitizeHtml($bodyHtml);
        $safeBody = $bodyHtml !== '' ? $bodyHtml : e($body);
        if ($bodyHtml === '') {
            $safeBody = preg_replace_callback(
                '~https?://[^\s&lt;&quot;]+~i',
                fn (array $match) => '<a href="'.e(html_entity_decode($match[0])).'" style="color:'.$primaryColor.';font-weight:700;text-decoration:underline;">'.$match[0].'</a>',
                $safeBody,
            );
            $safeBody = nl2br($safeBody);
        }
        $actionBlock = filter_var($actionUrl, FILTER_VALIDATE_URL) && str_starts_with(strtolower($actionUrl), 'https://')
            ? '<p style="margin:24px 0;"><a href="'.e($actionUrl).'" style="display:inline-block;padding:14px 22px;background:'.$primaryColor.';color:#fff;text-decoration:none;border-radius:8px;font-weight:700;">Complete restaurant setup</a></p><p style="font-size:12px;overflow-wrap:anywhere;">Or open this secure link: <a href="'.e($actionUrl).'">'.e($actionUrl).'</a></p>'
            : '';
        $otpBlock = $otpCode !== '' && preg_match('/^[0-9A-Za-z -]{4,16}$/', $otpCode)
            ? '<div style="margin:18px 0 4px;padding:18px 20px;border:1px solid #d0d7de;border-radius:14px;background:#f8fafc;text-align:center;"><div style="margin-bottom:8px;color:#667085;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">Tap and hold to copy code</div><div style="font-family:Arial,Helvetica,sans-serif;font-size:32px;font-weight:900;letter-spacing:.28em;color:#111827;user-select:all;-webkit-user-select:all;">'.e($otpCode).'</div></div>'
            : '';
        $logo = filter_var($logoUrl, FILTER_VALIDATE_URL) && str_starts_with(strtolower($logoUrl), 'https://')
            ? '<img src="'.e($logoUrl).'" width="56" height="56" alt="'.$safeName.'" style="display:block;width:56px;height:56px;object-fit:contain;border-radius:14px;background:#fff;">'
            : '<div style="width:56px;height:56px;line-height:56px;text-align:center;border-radius:14px;background:'.$primaryColor.';color:#fff;font-size:24px;font-weight:800;">'.e(mb_strtoupper(mb_substr($restaurantName ?: 'R', 0, 1))).'</div>';

        return '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"></head>'
            .'<body style="margin:0;padding:0;background:#f3f6f2;font-family:Arial,Helvetica,sans-serif;color:#172033;">'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f6f2;padding:28px 12px;"><tr><td align="center">'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#fff;border:1px solid #e2e9df;border-radius:20px;overflow:hidden;box-shadow:0 12px 34px rgba(31,52,25,.08);">'
            .'<tr><td style="height:5px;background:'.$primaryColor.';"></td></tr>'
            .'<tr><td style="padding:24px 28px;background:#fafafa;border-bottom:1px solid #e4e7ec;"><table role="presentation" cellspacing="0" cellpadding="0"><tr><td>'.$logo.'</td><td style="padding-left:15px;"><div style="font-size:20px;font-weight:800;">'.$safeName.'</div><div style="margin-top:4px;color:#667085;font-size:13px;">Secure customer notification</div></td></tr></table></td></tr>'
            .'<tr><td style="padding:30px 28px 14px;"><h1 style="margin:0 0 18px;font-size:23px;line-height:1.3;">'.$safeSubject.'</h1><div style="font-size:16px;line-height:1.75;color:#374151;">'.$safeBody.'</div>'.$actionBlock.$otpBlock.'</td></tr>'
            .'<tr><td style="padding:18px 28px 28px;"><div style="padding:14px 16px;border-radius:12px;background:#f3f9ef;color:#496442;font-size:12px;line-height:1.55;"><strong style="color:#347d24;">Security reminder</strong><br>Never share verification codes, passwords, or payment credentials. '.$safeName.' will never ask for them by phone or chat.</div></td></tr>'
            .'<tr><td style="padding:20px 28px;background:#172033;color:#cfd6df;font-size:12px;line-height:1.6;text-align:center;">Sent securely by '.$safeName.'<br><span style="color:#8f9baa;">This is an automated transactional message.</span></td></tr>'
            .'</table></td></tr></table></body></html>';
    }

    private function safeColor(string $color): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', trim($color)) ? trim($color) : '#F57C00';
    }

    private function sanitizeHtml(string $html): string
    {
        if (blank($html)) return '';

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,ul,ol,li,a[href|target],h1,h2,h3,blockquote');
        $config->set('URI.DisableExternalResources', true);
        $config->set('URI.AllowedSchemes', ['https' => true, 'mailto' => true]);
        $config->set('Attr.EnableID', false);

        return (new \HTMLPurifier($config))->purify($html);
    }
}

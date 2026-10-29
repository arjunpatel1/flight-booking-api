<?php

namespace Modules\Notification\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Notification\Services\TenantMailerService;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Lets a tenant admin connect their own outgoing mail server (Gmail, Workspace,
 * any SMTP host) so their mail is sent from their own address.
 */
class TenantMailSettingsController extends Controller
{
    public function __construct(private readonly TenantMailerService $mailers)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->present($this->tenantId($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $this->validated($request, $tenantId);

        $this->mailers->save($tenantId, $data);

        return ApiResponse::updated($this->present($tenantId), 'mail server');
    }

    /**
     * Send a test message with the submitted settings, before or after saving.
     */
    public function test(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $this->validated($request, $tenantId, requireServer: true);
        $recipient = $request->validate(['test_recipient' => ['required', 'email:rfc', 'max:255']])['test_recipient'];

        $settings = [
            ...$data,
            // A blank password in the form means "use the one already saved".
            'password' => filled($data['password'] ?? null)
                ? $data['password']
                : $this->mailers->settings($tenantId)['password'],
        ];

        try {
            $mailer = $this->mailers->build($tenantId, $settings);
            $mailer['mailer']->raw(
                "Your mail server is connected. Booking emails and Tenant Mail messages will be sent from {$mailer['from_address']}.",
                fn ($message) => $message
                    ->from($mailer['from_address'], $mailer['from_name'] ?: (string) config('mail.from.name'))
                    ->to($recipient)
                    ->subject('Mail server test'),
            );
        } catch (Throwable $exception) {
            return ApiResponse::errors(
                ['connection' => [$exception->getMessage()]],
                'The mail server rejected the test message: '.$exception->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return ApiResponse::success(['recipient' => $recipient], "Test email sent to {$recipient}.");
    }

    private function validated(Request $request, int $tenantId, bool $requireServer = false): array
    {
        $needsServer = $requireServer ? 'required' : 'required_if:enabled,true';
        // A password is only mandatory the first time; afterwards blank keeps it.
        $needsPassword = $this->mailers->hasPassword($tenantId) ? 'nullable' : $needsServer;

        return $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => [$needsServer, 'nullable', 'string', 'max:255'],
            'port' => [$needsServer, 'nullable', 'integer', 'between:1,65535'],
            'username' => [$needsServer, 'nullable', 'string', 'max:255'],
            'password' => [$needsPassword, 'nullable', 'string', 'max:255'],
            'encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
            'from_address' => [$needsServer, 'nullable', 'email:rfc', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * The settings as shown to the browser. The password never leaves the server.
     */
    private function present(int $tenantId): array
    {
        $settings = $this->mailers->settings($tenantId);
        $hasPassword = filled($settings['password']);
        unset($settings['password']);

        return [...$settings, 'has_password' => $hasPassword];
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $request->user()?->tenantId();
        abort_if(! $tenantId, Response::HTTP_FORBIDDEN, 'Only an agency account can set its own mail server.');

        return (int) $tenantId;
    }
}

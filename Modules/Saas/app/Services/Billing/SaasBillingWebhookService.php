<?php

namespace Modules\Saas\Services\Billing;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Saas\Models\SaasBillingInvoice;
use Modules\Saas\Services\Onboarding\OnboardingRequestService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SaasBillingWebhookService
{
    public function __construct(private readonly SaasBillingService $billing)
    {
    }

    public function handleRazorpay(Request $request): array
    {
        $payload = $this->verifiedJson($request, (string) config('saas.billing.razorpay.webhook_secret'), 'X-Razorpay-Signature');
        $event = (string) Arr::get($payload, 'event');

        if (! in_array($event, ['payment_link.paid', 'payment.captured'], true)) {
            return ['status' => 'ignored', 'event' => $event];
        }

        $reference = Arr::get($payload, 'payload.payment_link.entity.reference_id')
            ?: Arr::get($payload, 'payload.payment.entity.notes.invoice_number');

        $gatewayReference = Arr::get($payload, 'payload.payment.entity.id')
            ?: Arr::get($payload, 'payload.payment_link.entity.id');

        // A payment for a customer who has no tenant yet settles an onboarding
        // request, not an invoice. The webhook only marks it paid — the request
        // state machine decides whether provisioning follows.
        if ($settled = $this->settleOnboardingRequest('razorpay', $reference, $event, $gatewayReference, $payload)) {
            return $settled;
        }

        $invoice = $this->invoiceByReference($reference);
        $this->verifyServiceAmount($invoice, 'razorpay', Arr::get($payload, 'payload.payment.entity.amount'), Arr::get($payload, 'payload.payment.entity.currency'), Arr::get($payload, 'payload.payment.entity.status') === 'captured');

        return [
            'status' => 'processed',
            'gateway' => 'razorpay',
            'event' => $event,
            'invoice' => $this->billing->markPaid($invoice, [
                'gateway_reference' => $gatewayReference,
                'gateway_event' => $event,
                'payload' => $payload,
            ]),
        ];
    }

    public function handleStripe(Request $request): array
    {
        $secret = (string) config('saas.billing.stripe.webhook_secret');
        abort_unless($secret !== '', 422, 'Stripe webhook secret is not configured.');

        $body = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature');
        abort_unless($this->validStripeSignature($body, $signature, $secret), 403, 'Invalid Stripe webhook signature.');

        $payload = json_decode($body, true);
        abort_unless(is_array($payload), 422, 'Invalid webhook payload.');

        $event = (string) Arr::get($payload, 'type');
        if (! in_array($event, ['checkout.session.completed', 'payment_intent.succeeded'], true)) {
            return ['status' => 'ignored', 'event' => $event];
        }

        $reference = Arr::get($payload, 'data.object.client_reference_id')
            ?: Arr::get($payload, 'data.object.metadata.invoice_number');

        $gatewayReference = Arr::get($payload, 'data.object.payment_intent')
            ?: Arr::get($payload, 'data.object.id');

        if ($settled = $this->settleOnboardingRequest('stripe', $reference, $event, $gatewayReference, $payload)) {
            return $settled;
        }

        $invoice = $this->invoiceByReference($reference);
        $object = (array) Arr::get($payload, 'data.object', []);
        $this->verifyServiceAmount($invoice, 'stripe', $event === 'checkout.session.completed' ? ($object['amount_total'] ?? null) : ($object['amount_received'] ?? null), $object['currency'] ?? null, $event === 'checkout.session.completed' ? ($object['payment_status'] ?? null) === 'paid' : ($object['status'] ?? null) === 'succeeded');

        return [
            'status' => 'processed',
            'gateway' => 'stripe',
            'event' => $event,
            'invoice' => $this->billing->markPaid($invoice, [
                'gateway_reference' => $gatewayReference,
                'gateway_event' => $event,
                'payload' => $payload,
            ]),
        ];
    }

    /**
     * Settle a pre-tenant payment against its onboarding request.
     *
     * Returns null when the reference belongs to no open request, so the caller
     * falls through to the ordinary invoice path. This is deliberately the only
     * contact point between payments and onboarding: it marks the request paid
     * and returns. Whether a tenant gets provisioned is the request's approval
     * mode to decide, never the gateway's.
     */
    private function settleOnboardingRequest(
        string $gateway,
        mixed $reference,
        string $event,
        ?string $gatewayReference,
        array $payload,
    ): ?array {
        $requests = app(OnboardingRequestService::class);

        $request = $requests->findByPaymentReference($gateway, (string) $reference)
            ?? $requests->findByPaymentReference($gateway, (string) $gatewayReference);

        if (! $request) {
            return null;
        }

        $request = $requests->markPaid($request, [
            'gateway' => $gateway,
            'reference' => (string) ($reference ?: $gatewayReference),
            'gateway_event' => $event,
            'gateway_reference' => $gatewayReference,
        ]);

        Log::info('Onboarding request settled from payment webhook.', [
            'gateway' => $gateway,
            'event' => $event,
            'request_uuid' => $request->uuid,
            'status' => $request->status->value,
        ]);

        return [
            'status' => 'processed',
            'gateway' => $gateway,
            'event' => $event,
            'onboarding_request' => [
                'uuid' => $request->uuid,
                'status' => $request->status->value,
                'payment_status' => $request->payment_status,
                'approval_mode' => $request->approval_mode,
            ],
        ];
    }

    private function verifiedJson(Request $request, string $secret, string $signatureHeader): array
    {
        abort_unless($secret !== '', 422, 'Webhook secret is not configured.');

        $body = $request->getContent();
        $signature = (string) $request->header($signatureHeader);
        $expected = hash_hmac('sha256', $body, $secret);
        abort_unless(hash_equals($expected, $signature), 403, 'Invalid webhook signature.');

        $payload = json_decode($body, true);
        abort_unless(is_array($payload), 422, 'Invalid webhook payload.');

        return $payload;
    }

    private function verifyServiceAmount(SaasBillingInvoice $invoice, string $gateway, mixed $amount, mixed $currency, bool $settled): void
    {
        if (!data_get($invoice->metadata, 'service_feature')) return;
        abort_unless($invoice->gateway === $gateway && $settled && is_numeric($amount)
            && bccomp((string) $amount, bcmul((string) $invoice->amount, '100', 0), 0) === 0
            && strtoupper((string) $currency) === strtoupper($invoice->currency), 422, 'Service invoice payment amount, currency, gateway or settlement state does not match.');
    }

    private function validStripeSignature(string $body, string $signature, string $secret): bool
    {
        parse_str(str_replace(',', '&', $signature), $parts);
        $timestamp = $parts['t'] ?? null;
        $signed = $parts['v1'] ?? null;

        if (! $timestamp || ! $signed || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', "{$timestamp}.{$body}", $secret), $signed);
    }

    private function invoiceByReference(mixed $reference): SaasBillingInvoice
    {
        $reference = Str::of((string) $reference)->trim()->toString();

        if ($reference === '') {
            throw new HttpException(422, 'Invoice reference is missing.');
        }

        return SaasBillingInvoice::query()
            ->withoutGlobalScopes()
            ->where('invoice_number', $reference)
            ->firstOrFail();
    }
}

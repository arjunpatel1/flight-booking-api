<?php

namespace Modules\Saas\Services\Billing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Services\NotificationDispatcherService;
use Modules\Saas\Models\SaasBillingInvoice;
use Modules\Saas\Models\TenantSubscription;
use Modules\Saas\Models\SaasBillingRefund;
use Illuminate\Validation\ValidationException;

class SaasBillingService
{
    public function createInvoice(TenantSubscription $subscription, ?float $amount = null): SaasBillingInvoice
    {
        $subscription->loadMissing('tenant', 'plan');
        $plan = $subscription->plan;

        $invoice = SaasBillingInvoice::query()->create([
            'tenant_id' => $subscription->tenant_id,
            'tenant_subscription_id' => $subscription->id,
            'invoice_number' => $this->invoiceNumber($subscription),
            'amount' => $amount ?? (float) ($plan?->price ?? 0),
            'currency' => $plan?->currency ?? config('saas.billing.currency', 'INR'),
            'status' => 'issued',
            'gateway' => config('saas.billing.gateway', 'manual'),
            'issued_at' => now(),
            'due_at' => now()->addDays((int) config('saas.billing.due_days', 7)),
            'metadata' => [
                'plan' => $plan?->only(['id', 'name', 'code', 'billing_cycle']),
            ],
        ]);

        $invoice->events()->create([
            'type' => 'invoice_created',
            'status' => 'processed',
            'message' => "Invoice {$invoice->invoice_number} created.",
            'processed_at' => now(),
        ]);

        return $invoice->refresh();
    }

    public function createPaymentIntent(SaasBillingInvoice $invoice, string $gateway = 'razorpay'): SaasBillingInvoice
    {
        $invoice->loadMissing('tenant');

        if (! in_array($invoice->status, ['issued', 'payment_pending', 'overdue'], true)) {
            throw ValidationException::withMessages(['invoice' => 'Only an open invoice can receive a payment link.']);
        }

        if ($gateway === 'razorpay' && config('saas.billing.razorpay.key_id') && config('saas.billing.razorpay.key_secret')) {
            $this->applyGatewayPayload($invoice, 'razorpay', $this->razorpayPaymentLink($invoice));
        } elseif ($gateway === 'stripe' && config('saas.billing.stripe.secret')) {
            $this->applyGatewayPayload($invoice, 'stripe', $this->stripeCheckoutSession($invoice));
        } else {
            throw ValidationException::withMessages(['gateway' => ucfirst($gateway).' payment links are not configured.']);
        }

        $invoice->events()->create([
            'type' => 'payment_intent_created',
            'status' => 'processed',
            'message' => "Payment intent created using {$gateway}.",
            'payload' => ['gateway' => $gateway, 'payment_url' => $invoice->payment_url],
            'processed_at' => now(),
        ]);

        return $invoice->refresh();
    }

    public function markPaid(SaasBillingInvoice $invoice, array $payload = []): SaasBillingInvoice
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($invoice, $payload) {
            $locked = SaasBillingInvoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            return $this->markPaidLocked($locked, $payload);
        });
    }

    private function markPaidLocked(SaasBillingInvoice $invoice, array $payload): SaasBillingInvoice
    {
        if ($invoice->status === 'paid') {
            return $invoice->refresh();
        }
        if (data_get($invoice->metadata, 'service_feature') && !in_array($invoice->status, ['issued','payment_pending','overdue'], true)) {
            throw ValidationException::withMessages(['invoice' => 'A refunded or closed service invoice cannot be settled again.']);
        }

        $invoice->update([
            'status' => 'paid',
            'paid_at' => now(),
            'gateway_reference' => $payload['gateway_reference'] ?? $invoice->gateway_reference,
            'metadata' => [
                ...($invoice->metadata ?: []),
                'paid_payload' => $payload,
            ],
        ]);

        // Paying an add-on cannot reactivate a suspended restaurant or its
        // base subscription. Service access reads the paid invoice separately.
        if (!data_get($invoice->metadata, 'service_feature')) {
            $invoice->subscription?->update(['status' => 'active']);
            $invoice->tenant?->update(['is_active' => true]);
        }

        $invoice->events()->create([
            'type' => 'payment_completed',
            'status' => 'processed',
            'message' => 'Payment receipt generated.',
            'payload' => $payload,
            'processed_at' => now(),
        ]);

        $this->sendBillingAlert($invoice->refresh(), 'saas_invoice_paid');

        return $invoice->refresh();
    }

    public function runDunning(): array
    {
        $invoices = SaasBillingInvoice::query()
            ->with('tenant')
            ->whereIn('status', ['issued', 'payment_pending', 'overdue'])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->get();

        return $invoices->map(function (SaasBillingInvoice $invoice) {
            $invoice->update(['status' => 'overdue']);
            $event = $this->sendBillingAlert($invoice, 'saas_invoice_overdue');

            return [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'tenant' => $invoice->tenant?->name,
                'event_id' => $event?->id,
            ];
        })->all();
    }

    public function refund(SaasBillingInvoice $invoice, float $amount, string $reason, ?int $actorId, ?string $reference = null): SaasBillingRefund
    {
        $refunded = (float) $invoice->refunds()->sum('amount');
        if (! in_array($invoice->status, ['paid', 'partially_refunded'], true) || $amount <= 0 || $refunded + $amount > (float) $invoice->amount) {
            throw ValidationException::withMessages(['amount' => 'Refund must be positive and cannot exceed the paid invoice balance.']);
        }
        $refund = $invoice->refunds()->create([
            'amount' => $amount, 'currency' => $invoice->currency, 'status' => 'processed',
            'gateway_reference' => $reference, 'reason' => $reason, 'created_by' => $actorId,
        ]);
        $total = $refunded + $amount;
        $invoice->update([
            'status' => $total >= (float) $invoice->amount ? 'refunded' : 'partially_refunded',
            'metadata' => [...($invoice->metadata ?: []), 'refunded_amount' => $total],
        ]);
        $invoice->events()->create([
            'type' => 'refund_processed', 'status' => 'processed', 'message' => 'Refund recorded.',
            'payload' => ['refund_id' => $refund->id, 'amount' => $amount, 'reason' => $reason], 'processed_at' => now(),
        ]);
        return $refund;
    }

    public function taxReport(?string $from=null, ?string $to=null): array
    {
        $rate=(float)config('saas.billing.gst_rate',18); $rows=SaasBillingInvoice::query()->with(['tenant:id,name','refunds'])->when($from,fn($q)=>$q->whereDate('issued_at','>=',$from))->when($to,fn($q)=>$q->whereDate('issued_at','<=',$to))->get();
        $items=$rows->map(function($invoice)use($rate){$gross=(float)$invoice->amount;$tax=round($gross*$rate/(100+$rate),2);$refund=(float)$invoice->refunds->sum('amount');return ['invoice_id'=>$invoice->id,'invoice_number'=>$invoice->invoice_number,'tenant'=>$invoice->tenant?->name,'status'=>$invoice->status,'issued_at'=>$invoice->issued_at?->toIso8601String(),'gross'=>$gross,'taxable'=>round($gross-$tax,2),'gst'=>$tax,'refunded'=>$refund,'net_collected'=>$invoice->paid_at?max(0,$gross-$refund):0];});
        return ['rate'=>$rate,'summary'=>['invoices'=>$items->count(),'gross'=>round($items->sum('gross'),2),'taxable'=>round($items->sum('taxable'),2),'gst'=>round($items->sum('gst'),2),'refunded'=>round($items->sum('refunded'),2),'net_collected'=>round($items->sum('net_collected'),2)],'rows'=>$items->values()];
    }

    public function sendBillingAlert(SaasBillingInvoice $invoice, string $type = 'saas_invoice_notice')
    {
        $invoice->loadMissing('tenant');
        $recipient = $invoice->tenant?->contact_email;

        if (! $recipient) {
            return $invoice->events()->create([
                'type' => $type,
                'channel' => 'none',
                'status' => 'failed',
                'message' => 'Tenant contact email missing.',
                'processed_at' => now(),
            ]);
        }

        $logs = app(NotificationDispatcherService::class)->dispatch(
            $type,
            $recipient,
            [
                'title' => 'NexDine SaaS billing notice',
                'message' => "Invoice {$invoice->invoice_number} is {$invoice->status}.",
                'invoice_number' => $invoice->invoice_number,
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
                'payment_url' => $invoice->payment_url,
            ],
            [NotificationChannel::Email, NotificationChannel::InApp]
        );

        return $invoice->events()->create([
            'type' => $type,
            'channel' => 'email,in_app',
            'status' => 'processed',
            'payload' => ['notification_logs' => collect($logs)->pluck('id')->all()],
            'processed_at' => now(),
        ]);
    }

    private function invoiceNumber(TenantSubscription $subscription): string
    {
        return 'SAAS-'.now()->format('Ymd').'-'.$subscription->tenant_id.'-'.Str::upper(Str::random(6));
    }

    private function razorpayPaymentLink(SaasBillingInvoice $invoice): array
    {
        $config = config('saas.billing.razorpay');
        $response = Http::baseUrl('https://api.razorpay.com/v1')
            ->withBasicAuth((string) $config['key_id'], (string) $config['key_secret'])
            ->acceptJson()
            ->asJson()
            ->post('/payment_links', [
                'amount' => (int) round(((float) $invoice->amount) * 100),
                'currency' => $invoice->currency,
                'description' => "NexDine subscription {$invoice->invoice_number}",
                'customer' => [
                    'name' => $invoice->tenant?->name,
                    'email' => $invoice->tenant?->contact_email,
                    'contact' => $invoice->tenant?->contact_phone,
                ],
                'notify' => ['email' => true, 'sms' => false],
                'reference_id' => $invoice->invoice_number,
                'notes' => [
                    'invoice_number' => $invoice->invoice_number,
                    'tenant_id' => (string) $invoice->tenant_id,
                    'tenant_subscription_id' => (string) $invoice->tenant_subscription_id,
                ],
            ]);

        return $response->json() ?: ['error' => $response->body()];
    }

    private function stripeCheckoutSession(SaasBillingInvoice $invoice): array
    {
        $config = config('saas.billing.stripe');
        $response = Http::baseUrl('https://api.stripe.com/v1')
            ->withToken((string) $config['secret'])
            ->asForm()
            ->post('/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $config['success_url'],
                'cancel_url' => $config['cancel_url'],
                'client_reference_id' => $invoice->invoice_number,
                'customer_email' => $invoice->tenant?->contact_email,
                'metadata' => [
                    'invoice_number' => $invoice->invoice_number,
                    'tenant_id' => (string) $invoice->tenant_id,
                    'tenant_subscription_id' => (string) $invoice->tenant_subscription_id,
                ],
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($invoice->currency),
                        'unit_amount' => (int) round(((float) $invoice->amount) * 100),
                        'product_data' => [
                            'name' => "NexDine subscription {$invoice->invoice_number}",
                        ],
                    ],
                ]],
            ]);

        return $response->json() ?: ['error' => $response->body()];
    }

    private function applyGatewayPayload(SaasBillingInvoice $invoice, string $gateway, array $payload): void
    {
        $paymentUrl = $payload['short_url'] ?? $payload['url'] ?? null;
        if (! filled($paymentUrl)) {
            throw ValidationException::withMessages([
                'gateway' => ucfirst($gateway).' did not return a usable payment URL.',
            ]);
        }

        $invoice->update([
            'gateway' => $gateway,
            'gateway_reference' => $payload['id'] ?? null,
            'payment_url' => $paymentUrl,
            'status' => 'payment_pending',
            'metadata' => [
                ...($invoice->metadata ?: []),
                'gateway_payload' => $payload,
            ],
        ]);
    }
}

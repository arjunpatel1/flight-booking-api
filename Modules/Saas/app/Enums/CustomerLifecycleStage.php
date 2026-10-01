<?php

namespace Modules\Saas\Enums;

/**
 * The commercial lifecycle of a customer, from first contact to churn.
 *
 * Deliberately NOT a separate model. Stages before a tenant exists live on the
 * onboarding request; stages after live on the tenant. One enum spans both so
 * there is a single vocabulary, and every transition is audited as a
 * SaasCustomerSuccessRecord of type `lifecycle` — reusing Customer Success
 * rather than duplicating it.
 */
enum CustomerLifecycleStage: string
{
    // Pre-tenant — tracked on the onboarding request.
    case Lead = 'lead';
    case Demo = 'demo';
    case Trial = 'trial';
    case PaymentPending = 'payment_pending';
    case Paid = 'paid';
    case Provisioning = 'provisioning';

    // Post-tenant — tracked on the tenant.
    case Onboarding = 'onboarding';
    case Training = 'training';
    case GoLive = 'go_live';
    case Healthy = 'healthy';
    case Renewal = 'renewal';
    case Cancelled = 'cancelled';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Stages that exist before a tenant record does. */
    public static function preTenant(): array
    {
        return [self::Lead, self::Demo, self::Trial, self::PaymentPending, self::Paid, self::Provisioning];
    }

    public function isPreTenant(): bool
    {
        return in_array($this, self::preTenant(), true);
    }

    /** Ordinal position, used for "moved forward" vs "moved back" reporting. */
    public function order(): int
    {
        return array_search($this, self::cases(), true) ?: 0;
    }

    public function label(): string
    {
        return match ($this) {
            self::GoLive => 'Go Live',
            self::PaymentPending => 'Payment Pending',
            // "Healthy" is an operational health result displayed in the
            // adjacent registry column. The lifecycle meaning here is that
            // the restaurant is a live customer, so use a distinct label.
            self::Healthy => 'Live',
            default => ucwords(str_replace('_', ' ', $this->value)),
        };
    }
}

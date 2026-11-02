<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Support\Eloquent\Model;
use Modules\Support\Enums\PriceType;

/**
 * Shared rules for a tenant's flight coupons and flight discounts. Each lives
 * in its own table and always belongs to one tenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property array $name        per language, e.g. ['en' => '10% Off']
 * @property array|null $description
 * @property string|null $code
 * @property PriceType $type
 * @property string $value
 * @property array|null $conditions
 * @property int $used
 */
abstract class FlightOffer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'code',
        'type',
        'value',
        'max_discount',
        'minimum_spend',
        'maximum_spend',
        'conditions',
        'usage_limit',
        'per_customer_limit',
        'is_active',
        'start_date',
        'end_date',
        'created_by',
    ];

    /** 'coupon' or 'discount', as stored in flight_offer_redemptions.offer_type. */
    abstract public function kind(): string;

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'conditions' => 'array',
            'type' => PriceType::class,
            'is_active' => 'boolean',
            'usage_limit' => 'integer',
            'per_customer_limit' => 'integer',
            'used' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /** Offers belonging to one tenant only. */
    public function scopeForTenant(Builder $query, ?int $tenantId): void
    {
        $query->where('tenant_id', (int) $tenantId);
    }

    /** Active and inside its start/end dates today. */
    public function scopeAvailableNow(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', today()));
    }

    /** Text for a translatable field in the current language (falls back to English, then any). */
    public function localized(string $attribute): string
    {
        $value = $this->{$attribute};
        if (! is_array($value)) {
            return (string) ($value ?? '');
        }

        return (string) ($value[app()->getLocale()] ?? $value['en'] ?? (reset($value) ?: ''));
    }

    public function isPercent(): bool
    {
        return $this->type === PriceType::Percent;
    }

    /** "10%" or "₹500". */
    public function valueText(): string
    {
        $value = (float) $this->value;

        return $this->isPercent()
            ? rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') . '%'
            : '₹' . number_format($value, 2);
    }

    public function usageLimitReached(): bool
    {
        return $this->usage_limit !== null && $this->used >= $this->usage_limit;
    }

    /** Discount this offer gives on $subtotal, capped by max_discount and the subtotal. */
    public function discountOn(float $subtotal): float
    {
        $value = (float) $this->value;
        $discount = $this->isPercent() ? $subtotal * $value / 100 : $value;
        if ($this->isPercent() && $this->max_discount !== null && (float) $this->max_discount > 0) {
            $discount = min($discount, (float) $this->max_discount);
        }

        return round(min($subtotal, max(0, $discount)), 2);
    }
}

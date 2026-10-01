<?php

namespace Modules\Order\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Aggregator\Models\AggregatorOrderMapping;
use Modules\Aggregator\Models\PartnerApiOrderMapping;
use Modules\Invoice\Models\Invoice;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDiscount;
use Modules\Order\Models\OrderDelivery;
use Modules\Order\Models\OrderProduct;
use Modules\Order\Models\OrderStatusLog;
use Modules\Order\Models\OrderTax;
use Modules\Payment\Models\Payment;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Models\PosSession;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\TableMerge;
use Modules\User\Models\User;

/**
 * Eloquent relationships for the Order model.
 */
trait HasOrderRelations
{
    public function delivery(): HasOne
    {
        return $this->hasOne(OrderDelivery::class);
    }

    public function modifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modified_by')
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id')
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id')
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id')
            ->withoutGlobalActive()
            ->withTrashed()
            ->withDefault([
                'id' => null,
                'name' => User::walkInName(),
            ]);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(OrderTax::class)
            ->whereNull('order_product_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(OrderProduct::class);
    }

    public function aggregatorOrderMapping(): HasOne
    {
        return $this->hasOne(AggregatorOrderMapping::class);
    }

    public function partnerApiOrderMapping(): HasOne
    {
        return $this->hasOne(PartnerApiOrderMapping::class);
    }

    public function whatsAppOrderSession(): HasOne
    {
        return $this->hasOne(\Modules\WhatsAppCenter\Models\WhatsAppOrderSession::class);
    }

    public function posRegister(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class)
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class)
            ->withOutGlobalBranchPermission()
            ->withTrashed();
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class)->orderBy('id', 'desc');
    }

    public function voiceHistory(): HasMany
    {
        return $this->hasMany(\Modules\Voice\Models\VoiceHistory::class, 'order_id');
    }

    public function tableMerge(): BelongsTo
    {
        return $this->belongsTo(TableMerge::class)
            ->withOutGlobalBranchPermission()
            ->withTrashed();
    }

    public function mergedIntoOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class)
            ->withOutGlobalBranchPermission();
    }

    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by')
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class)
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)
            ->withOutGlobalBranchPermission()
            ->withTrashed();
    }

    public function discount(): HasOne
    {
        return $this->hasOne(OrderDiscount::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function mergedInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'table_merge_id');
    }
}

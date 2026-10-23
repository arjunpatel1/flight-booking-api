<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Flight\Enums\SupplierMappingType;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

/**
 * Phase 5: Supplier Mapping Model
 *
 * Maps external supplier codes (airline, flight, fare basis, inventory)
 * and transaction identifiers (supplier booking reference, supplier PNR, ticket number)
 * to internal Flight domain entities.
 */
class FlightSupplierMapping extends Model
{
    use BelongsToTenant, HasFilters, HasSortBy;

    protected $table = 'flight_supplier_mappings';

    protected $fillable = [
        'flight_supplier_id',
        'tenant_id',
        'mapping_type',
        'internal_id',
        'internal_code',
        'supplier_code',
        'supplier_reference',
        'supplier_pnr',
        'supplier_ticket_number',
        'metadata',
    ];

    protected $casts = [
        'mapping_type' => SupplierMappingType::class,
        'internal_id'  => 'integer',
        'metadata'     => 'array',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FlightSupplier::class, 'flight_supplier_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForType(Builder $query, SupplierMappingType|string $type): void
    {
        $val = $type instanceof SupplierMappingType ? $type->value : $type;
        $query->where('mapping_type', $val);
    }

    public function scopeByPnr(Builder $query, string $pnr): void
    {
        $query->where('supplier_pnr', strtoupper($pnr));
    }

    public function scopeByTicket(Builder $query, string $ticketNumber): void
    {
        $query->where('supplier_ticket_number', $ticketNumber);
    }

    public function scopeByReference(Builder $query, string $reference): void
    {
        $query->where('supplier_reference', $reference);
    }
}

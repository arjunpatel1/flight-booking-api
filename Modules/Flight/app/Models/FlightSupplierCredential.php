<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

/**
 * Phase 5: Encrypted Supplier Credentials
 *
 * Stores API keys, client secrets, GDS Office IDs, PCCs, and bearer tokens.
 * All sensitive credential attributes are encrypted at rest using AES-256-CBC.
 */
class FlightSupplierCredential extends Model
{
    use BelongsToTenant;

    protected $table = 'flight_supplier_credentials';

    protected $fillable = [
        'flight_supplier_id',
        'tenant_id',
        'client_id',
        'client_secret',
        'api_key',
        'auth_token',
        'office_id',
        'corporate_code',
        'certificate_path',
        'additional_credentials',
        'token_expires_at',
    ];

    protected $casts = [
        'client_secret'          => 'encrypted',
        'api_key'                => 'encrypted',
        'auth_token'             => 'encrypted',
        'additional_credentials' => 'encrypted:array',
        'token_expires_at'       => 'datetime',
    ];

    protected $hidden = [
        'client_secret',
        'api_key',
        'auth_token',
        'additional_credentials',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FlightSupplier::class, 'flight_supplier_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isTokenExpired(): bool
    {
        if (! $this->token_expires_at) {
            return false;
        }

        return $this->token_expires_at->isPast();
    }
}

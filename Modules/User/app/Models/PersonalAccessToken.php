<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Check if token has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Get active status attribute.
     */
    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn() => !$this->isExpired()
        );
    }
}

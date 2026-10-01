<?php

namespace Modules\Invoice\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Invoice\Enums\InvoicePartyType;
use Modules\Support\Country;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasLikeFilters;


/**
 * @property int $id
 * @property InvoicePartyType $type
 * @property string $legal_name
 * @property string|null $vat_tin
 * @property string|null $cr_number
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state
 * @property string $country_code
 * @property string|null $postal_code
 * @property string|null $phone
 * @property string|null $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * @property-read string|null $country_name
 */
class InvoiceParty extends Model
{
    use SoftDeletes, HasLikeFilters;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "type",
        "legal_name",
        "vat_tin",
        "cr_number",
        "address_line1",
        "address_line2",
        "city",
        "state",
        "country_code",
        "postal_code",
        "phone",
        "email",
    ];

    /**
     * Get country name
     *
     * @return Attribute
     */
    public function countryName(): Attribute
    {
        return Attribute::get(fn() => $this->country_code ? Country::name($this->country_code) : null);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InvoicePartyType::class,
        ];
    }
}

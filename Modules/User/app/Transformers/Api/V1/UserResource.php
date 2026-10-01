<?php

namespace Modules\User\Transformers\Api\V1;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\User\Models\User;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $includePassword = $request->query('include_password') === 'true';
        $tenantWide = blank($this->attribute('branch_id'))
            && $this->roles->contains(
                fn ($role) => $role->name === \Modules\User\Enums\DefaultRole::EnterpriseAdmin->value
            );

        return [
            'id' => $this->attribute('id'),
            "branch" => [
                "id" => $this->attribute('branch_id'),
                "name" => $tenantWide
                    ? __('saas::workspace.ui.panels.all_branches')
                    : ($this->relationLoaded("branch") ? $this->branch?->name : ""),
            ],
            'access_scope' => $tenantWide ? 'tenant' : 'branch',
            'tenant_id' => $this->attribute('tenant_id'),
            "profile_photo_url" => $this->hasSelectedAttribute('name') ? $this->profile_photo_url : null,
            "profile_photo" => $this->profile_photo
                ? new \Modules\Media\Transformers\Api\V1\MediaSimpleResource($this->profile_photo)
                : null,
            'name' => $this->attribute('name'),
            'username' => $this->attribute('username'),
            'phone' => $this->attribute('phone'),
            'phone_country_iso_code' => $this->attribute('phone_country_iso_code'),
            // national_phone is a computed accessor, not a column, so the
            // selected-attribute guard never matched and this always resolved
            // to null. The customer and user edit forms bind their phone field
            // to it, which is why the field came back empty and demanded a new
            // number on every save. Gate on the column it derives from instead.
            "national_phone" => $this->hasSelectedAttribute('phone')
                ? $this->nationalPhoneValue()
                : null,
            'email' => $this->attribute('email'),
            'gender' => $this->hasSelectedAttribute('gender') ? $this->gender?->toTrans() : null,
            'date_of_birth' => $this->dateAttribute('date_of_birth'),
            'anniversary_date' => $this->dateAttribute('anniversary_date'),
            'whatsapp_marketing_consent' => (bool) $this->attribute('whatsapp_marketing_consent'),
            'whatsapp_marketing_consented_at' => $this->dateTimeAttribute('whatsapp_marketing_consented_at'),
            'whatsapp_consent_source' => $this->attribute('whatsapp_consent_source'),
            'whatsapp_opted_out_at' => $this->dateTimeAttribute('whatsapp_opted_out_at'),
            'is_active' => $this->attribute('is_active'),
            // False marks staff recorded for scheduling/attendance only.
            'can_login' => $this->hasSelectedAttribute('can_login')
                ? (bool) $this->resource->can_login
                : null,
            'order_types' => $this->attribute('order_types') ?: [],
            'completed_orders_count' => (int) ($this->attribute('completed_orders_count') ?? 0),
            'total_sales' => round((float) ($this->attribute('total_sales') ?? 0), 2),
            'average_rating' => $this->attribute('average_rating') === null
                ? null
                : round((float) $this->attribute('average_rating'), 2),
            'feedback_count' => (int) ($this->attribute('feedback_count') ?? 0),
            'last_order_at' => $this->dateTimeAttribute('last_order_at'),
            'last_feedback_at' => $this->dateTimeAttribute('last_feedback_at'),
            "role" => isset($this->roles[0])
                ? [
                    "id" => $this->roles[0]->id,
                    "key" => $this->roles[0]->name,
                    "name" => $this->roles[0]->name,
                    "display_name" => $this->roles[0]->display_name
                ] : null,
            "roles" => $this->roles->map(fn ($role) => [
                "id" => $role->id,
                "key" => $role->name,
                "name" => $role->name,
                "display_name" => $role->display_name,
            ])->values(),
            "is_main_user" => $this->isMainUser(),
            "updated_at" => $this->dateTimeAttribute('updated_at'),
            "created_at" => $this->dateTimeAttribute('created_at'),
            'password' => $includePassword ? $this->attribute('password') : null,
        ];
    }

    /** Keeps null for a user with no phone rather than an empty string. */
    private function nationalPhoneValue(): ?string
    {
        $value = $this->resource->national_phone;

        return is_null($value) ? null : (string) $value;
    }

    private function hasSelectedAttribute(string $key): bool
    {
        return array_key_exists($key, $this->resource->getAttributes());
    }

    private function attribute(string $key): mixed
    {
        return $this->hasSelectedAttribute($key) ? $this->resource->getAttribute($key) : null;
    }

    private function dateTimeAttribute(string $key): ?string
    {
        $value = $this->attribute($key);

        if (blank($value)) {
            return null;
        }

        return dateTimeFormat($value instanceof Carbon ? $value : Carbon::parse($value));
    }

    private function dateAttribute(string $key): ?string
    {
        $value = $this->attribute($key);

        if (blank($value)) {
            return null;
        }

        return ($value instanceof Carbon ? $value : Carbon::parse($value))->toDateString();
    }
}

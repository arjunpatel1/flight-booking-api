<?php

namespace Modules\User\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Requests\Request;
use Modules\Order\Enums\OrderType;
use Modules\User\Enums\DefaultRole;
use Modules\User\Enums\GenderType;
use Modules\User\Http\Requests\Api\V1\Concerns\ValidatesTenantScopedUserIdentity;
use Modules\User\Services\Role\RoleServiceInterface;

/**
 * @property int|null $role
 */
class SaveUserRequest extends Request
{
    use ValidatesTenantScopedUserIdentity;

    protected function prepareForValidation(): void
    {
        if (!$this->has('roles') && $this->filled('role')) {
            $this->merge(['roles' => [(int) $this->input('role')]]);
        }

        $actor = auth()->user();
        if ($actor?->assignedToTenant() && ! $actor->isSuperAdmin()) {
            $this->merge(['tenant_id' => $actor->tenantId()]);
        } elseif (!$this->filled('tenant_id') && $this->filled('branch_id')) {
            $tenantId = Branch::query()
                ->withoutGlobalScopes()
                ->whereKey($this->integer('branch_id'))
                ->value('tenant_id');

            if (is_numeric($tenantId)) {
                $this->merge(['tenant_id' => (int) $tenantId]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        // Non-login staff (kitchen porters, cleaners) are recorded so they can
        // be scheduled and clocked in, but never sign in. Their credential
        // fields are optional; everything else about the record is unchanged.
        $canLogin = $this->boolean('can_login', true);
        $credentialRule = $canLogin ? 'required' : 'nullable';

        $rules = [
            ...$this->getBranchRule(true),
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'can_login' => ['nullable', 'boolean'],
            "name" => "required|string|max:255",
            "username" => ['bail', $credentialRule, 'string', 'max:255', $this->uniqueActiveUserValue('username')],
            "email" => ['bail', $credentialRule, 'email:rfc', 'max:50', $this->uniqueActiveUserValue('email')],
            "password" => [
                ($this->method() == 'PUT' || ! $canLogin ? 'nullable' : 'required'),
                'string',
                Password::min(8)
                    ->max(20)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
                "confirmed"
            ],
            "gender" => ["required", Rule::enum(GenderType::class)],
            "phone_country_iso_code" => "nullable|required_with:phone|string|max:3",
            "phone" => [
                "nullable",
                "phone:phone_country_iso_code",
            ],
            'role' => ['nullable', 'numeric'],
            'roles' => ['bail', 'required', 'array', 'min:1', 'max:5'],
            'roles.*' => [
                'bail',
                'required',
                'numeric',
                'distinct',
                Rule::exists('roles', 'id')
                    ->whereNot('name', DefaultRole::Customer->value),
            ],
            "category_slugs" => "nullable|array",
            "category_slugs.*" => "bail|required|string|exists:categories,slug,deleted_at,NULL",
            "order_types" => ["nullable", "array"],
            "order_types.*" => ["bail", "required", "string", Rule::enum(OrderType::class)],
            "printer_id" => "bail|nullable|exists:printers,id",
            "is_active" => "required|boolean",
        ];

        $selectedRoles = collect($this->input('roles', []))
            ->map(fn ($roleId) => app(RoleServiceInterface::class)->find((int) $roleId))
            ->filter();

        $isEnterpriseAdmin = $selectedRoles->contains(
            fn ($role) => $role->name === DefaultRole::EnterpriseAdmin->value
        );
        if ($this->method() === 'POST' && !$isEnterpriseAdmin && $selectedRoles->isNotEmpty()) {
                $rules['branch_id'] = str_replace("nullable", "required", $rules['branch_id']);
        }

        return $rules;
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $selectedRoleIds = array_map('intval', $this->input('roles', []));
                $visibleRoleIds = \Modules\User\Models\Role::query()
                    ->visibleTo(auth()->user())
                    ->whereIn('id', $selectedRoleIds)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if (array_diff($selectedRoleIds, $visibleRoleIds) !== []) {
                    $validator->errors()->add('roles', 'One or more selected roles are not available to this restaurant.');
                }

                if (auth()->user()->assignedToBranch()) {
                    $hasUnavailableBuiltInRole = \Modules\User\Models\Role::query()
                        ->whereIn('id', $selectedRoleIds)
                        ->where('built_in', true)
                        ->whereNotIn('name', DefaultRole::getBranchAvailableRoles())
                        ->exists();

                    if ($hasUnavailableBuiltInRole) {
                        $validator->errors()->add('roles', 'One or more selected roles cannot be assigned by a branch administrator.');
                    }
                }

                $enterpriseRoleId = \Modules\User\Models\Role::query()
                    ->where('name', DefaultRole::EnterpriseAdmin->value)
                    ->value('id');
                if (
                    $enterpriseRoleId
                    && in_array((int) $enterpriseRoleId, array_map('intval', $this->input('roles', [])), true)
                    && !auth()->user()->hasRole(DefaultRole::EnterpriseAdmin->value)
                    && !auth()->user()->can('admin.saas.manage')
                ) {
                    $validator->errors()->add('roles', 'Only an enterprise administrator or SaaS administrator can grant enterprise-wide access.');
                }

                if (!$this->filled('tenant_id') || !$this->filled('branch_id')) {
                    $this->validateWaiterOrderTypes($validator);
                    return;
                }

                $branchBelongsToTenant = \Modules\Branch\Models\Branch::query()
                    ->withoutGlobalScopes()
                    ->whereKey($this->integer('branch_id'))
                    ->where('tenant_id', $this->integer('tenant_id'))
                    ->exists();

                if (!$branchBelongsToTenant) {
                    $validator->errors()->add('branch_id', __('user::messages.branch_must_belong_to_tenant'));
                }

                $this->validateWaiterOrderTypes($validator);
            },
        ];
    }

    private function validateWaiterOrderTypes($validator): void
    {
        if (!$this->filled('roles') || !$this->filled('order_types')) {
            return;
        }

        $hasWaiterRole = collect($this->input('roles', []))
            ->map(fn ($roleId) => app(RoleServiceInterface::class)->find((int) $roleId)?->name)
            ->contains(DefaultRole::Waiter->value);
        if (!$hasWaiterRole) {
            return;
        }

        if (!$this->filled('branch_id')) {
            return;
        }

        $branch = Branch::withoutGlobalActive()->find($this->integer('branch_id'));
        $branchOrderTypes = $branch?->order_types ?: [];
        $selectedOrderTypes = collect($this->input('order_types', []))
            ->filter(fn($type) => is_string($type) || is_numeric($type))
            ->map(fn($type) => (string) $type)
            ->filter()
            ->unique()
            ->values();

        if ($selectedOrderTypes->isEmpty()) {
            return;
        }

        $hasInvalidType = $selectedOrderTypes
            ->contains(fn($type) => !in_array($type, $branchOrderTypes, true));

        if ($hasInvalidType) {
            $validator->errors()->add(
                'order_types',
                __('validation.in', ['attribute' => __('user::attributes.users.order_types')])
            );
        }
    }

    /** @inheritDoc */
    public function validationData(): array
    {
        $data = parent::validationData();
        $phone = $this->input('phone');
        $phoneCountryIsoCode = $this->input('phone_country_iso_code');

        if (!is_null($phone) && !is_null($phoneCountryIsoCode)) {
            $data['phone'] = phone($phone, $phoneCountryIsoCode);
        }

        return $data;
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "user::attributes.users";
    }
}

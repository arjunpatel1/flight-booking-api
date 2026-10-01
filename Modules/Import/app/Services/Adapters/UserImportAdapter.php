<?php

namespace Modules\Import\Services\Adapters;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Import\Contracts\ImportAdapter;
use Modules\Import\Services\Adapters\Concerns\BuildsTranslatedValues;
use Modules\User\Enums\GenderType;
use Modules\User\Services\User\UserServiceInterface;

class UserImportAdapter implements ImportAdapter
{
    use BuildsTranslatedValues;

    public function __construct(private readonly UserServiceInterface $users)
    {
    }

    public function import(array $row, array $options = []): void
    {
        $data = [
            'name' => $row['name'] ?? null,
            'username' => $row['username'] ?? null,
            'email' => $row['email'] ?? null,
            'password' => $row['password'] ?? null,
            'gender' => $row['gender'] ?? null,
            'role' => $row['role_id'] ?? $options['role_id'] ?? null,
            'branch_id' => $row['branch_id'] ?? $options['branch_id'] ?? null,
            'tenant_id' => $row['tenant_id'] ?? $options['tenant_id'] ?? null,
            'is_active' => $this->boolean($row, 'is_active', true),
        ];

        $tenantId = $data['tenant_id'];
        $tenantUnique = static fn (string $column) => Rule::unique('users', $column)
            ->where(static fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at'));

        Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', $tenantUnique('username')],
            'email' => ['required', 'email:rfc', 'max:50', $tenantUnique('email')],
            'password' => ['required', 'string', 'min:8', 'max:20'],
            'gender' => ['required', Rule::enum(GenderType::class)],
            'role' => ['required', 'integer', 'exists:roles,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id,deleted_at,NULL'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'is_active' => ['required', 'boolean'],
        ])->validate();

        $this->users->store($data);
    }
}

<?php

use Modules\User\Enums\{PermissionAction as Action};

return [
    'permissions' => [
        "users" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "customers" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "roles" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "employee_shifts" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "employee_attendances" => [Action::Index, Action::Create, Action::Edit, Action::ClockIn, Action::ClockOut, Action::Summary],
        "employee_compensations" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "employee_payroll" => [Action::Index, Action::Show, Action::Create, Action::Approve, Action::Export],
        "profiles" => [Action::Edit]
    ],
];

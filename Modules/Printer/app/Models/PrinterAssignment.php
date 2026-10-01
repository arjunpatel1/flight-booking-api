<?php

namespace Modules\Printer\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Traits\HasBranch;
use Modules\Printer\Enum\PrintContentType;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\Role;
use Modules\User\Models\User;

class PrinterAssignment extends Model
{
    use HasBranch;

    protected $fillable = [
        'branch_id',
        'scope',
        'print_type',
        'user_id',
        'role_id',
        'printer_id',
    ];

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    protected function casts(): array
    {
        return [
            'print_type' => PrintContentType::class,
        ];
    }
}

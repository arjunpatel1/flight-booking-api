<?php

namespace Modules\WhatsAppCenter\Models;

use Carbon\Carbon;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;

class WhatsAppSchedule extends Model
{
    use HasBranch;

    protected $table = 'whatsapp_schedules';

    protected $fillable = [
        'name',
        'report_type',
        'template_name',
        'frequency',
        'run_at',
        'day_of_week',
        'day_of_month',
        'recipients',
        'branch_id',
        'user_id',
        'filters',
        'is_active',
        'last_run_at',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'filters' => 'array',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(\Modules\Branch\Models\Branch::class);
    }

    public function user()
    {
        return $this->belongsTo(\Modules\User\Models\User::class)->withTrashed();
    }

    public function computeNextRunAt(): Carbon
    {
        $baseTime = Carbon::parse($this->run_at ?? '08:00');
        $now = now();

        return match ($this->frequency) {
            'daily' => $now->copy()->setTimeFrom($baseTime)->lessThanOrEqualTo($now)
                ? $now->copy()->addDay()->setTimeFrom($baseTime)
                : $now->copy()->setTimeFrom($baseTime),
            'weekly' => $now->copy()->next($this->day_of_week ?? Carbon::MONDAY)->setTimeFrom($baseTime),
            'monthly' => $now->copy()->startOfMonth()
                ->addDays(($this->day_of_month ?? 1) - 1)
                ->setTimeFrom($baseTime),
            default => $now->copy()->addDay()->setTimeFrom($baseTime),
        };
    }
}

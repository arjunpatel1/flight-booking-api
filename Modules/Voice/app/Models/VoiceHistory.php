<?php

namespace Modules\Voice\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Traits\HasBranch;
use Modules\Order\Models\Order;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Voice\Database\Factories\VoiceHistoryFactory;

/**
 * @property int $id
 * @property int $branch_id
 * @property-read \Modules\Branch\Models\Branch $branch
 * @property int|null $order_id
 * @property-read \Modules\Order\Models\Order|null $order
 * @property string $announcement_text
 * @property string $event_type
 * @property string $voice_gender
 * @property string|null $device_id
 * @property string|null $device_name
 * @property int $volume
 * @property int|null $duration
 * @property bool $success
 * @property string|null $error_message
 * @property int|null $created_by
 * @property-read \Modules\User\Models\User|null $createdBy
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class VoiceHistory extends Model
{
    use HasFactory,
        HasBranch,
        HasCreatedBy,
        HasFilters,
        HasSortBy;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'voice_history';

    /**
     * Default date column
     *
     * @var string
     */
    public static string $defaultDateColumn = 'created_at';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'branch_id',
        'order_id',
        'announcement_text',
        'event_type',
        'voice_gender',
        'device_id',
        'device_name',
        'volume',
        'duration',
        'success',
        'error_message',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'volume' => 'integer',
        'duration' => 'integer',
        'success' => 'boolean',
    ];

    /**
     * Get the order attached to this voice history record.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)
            ->withOutGlobalBranchPermission();
    }

    /**
     * Clean up old voice history records.
     */
    public static function cleanupOldRecords(int $retentionDays = 30): int
    {
        return self::where('created_at', '<', now()->subDays($retentionDays))->delete();
    }

    protected static function newFactory(): VoiceHistoryFactory
    {
        return VoiceHistoryFactory::new();
    }
}

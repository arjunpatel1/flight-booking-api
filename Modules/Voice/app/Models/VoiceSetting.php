<?php

namespace Modules\Voice\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Voice\Database\Factories\VoiceSettingFactory;

/**
 * @property int $id
 * @property int $branch_id
 * @property-read \Modules\Branch\Models\Branch $branch
 * @property bool $voice_enabled
 * @property string $voice_gender
 * @property int $voice_rate
 * @property int $voice_volume
 * @property string|null $selected_device_id
 * @property string|null $selected_device_name
 * @property bool $test_voice_enabled
 * @property int|null $delay_threshold_minutes
 * @property int|null $created_by
 * @property-read \Modules\User\Models\User|null $createdBy
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class VoiceSetting extends Model
{
    use HasFactory,
        HasBranch,
        HasCreatedBy,
        HasFilters,
        HasSortBy;

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
        'voice_enabled',
        'voice_gender',
        'voice_rate',
        'voice_volume',
        'selected_device_id',
        'selected_device_name',
        'test_voice_enabled',
        'delay_threshold_minutes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'voice_enabled' => 'boolean',
        'voice_rate' => 'integer',
        'voice_volume' => 'integer',
        'test_voice_enabled' => 'boolean',
    ];

    /**
     * Get default settings for a branch.
     */
    public static function getDefaultSettings(int $branchId): array
    {
        return [
            'branch_id' => $branchId,
            'voice_enabled' => true,
            'voice_gender' => 'Female',
            'voice_rate' => 0,
            'voice_volume' => 80,
            'selected_device_id' => null,
            'selected_device_name' => null,
            'test_voice_enabled' => true,
            'delay_threshold_minutes' => 30,
        ];
    }

    protected static function newFactory(): VoiceSettingFactory
    {
        return VoiceSettingFactory::new();
    }
}

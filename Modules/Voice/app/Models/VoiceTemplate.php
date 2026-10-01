<?php

namespace Modules\Voice\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Voice\Database\Factories\VoiceTemplateFactory;

/**
 * @property int $id
 * @property int $branch_id
 * @property-read \Modules\Branch\Models\Branch $branch
 * @property string $template_name
 * @property string $template_text
 * @property string $event_type
 * @property bool $is_default
 * @property int $priority
 * @property bool $is_active
 * @property int|null $created_by
 * @property-read \Modules\User\Models\User|null $createdBy
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class VoiceTemplate extends Model
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

    public const EVENT_TYPES = [
        'NewOrder',
        'SwiggyOrder',
        'ZomatoOrder',
        'CollectOrder',
        'OrderDelayed',
        'ManualAnnouncement',
        'TableReady',
        'PaymentSuccess',
        'KotReady',
        'OrderReady',
        'CustomAnnouncement',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'branch_id',
        'template_name',
        'template_text',
        'event_type',
        'is_default',
        'priority',
        'is_active',
    ];

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = ['id'];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_default' => 'boolean',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Get default templates for a branch.
     */
    public static function getDefaultTemplates(int $branchId): array
    {
        return [
            [
                'branch_id' => $branchId,
                'template_name' => 'New Order',
                'template_text' => 'New order received. Table {TableNumber}.',
                'event_type' => 'NewOrder',
                'is_default' => true,
                'priority' => 0,
                'is_active' => true,
            ],
            [
                'branch_id' => $branchId,
                'template_name' => 'New Order with Waiter',
                'template_text' => 'New order received. Table {TableNumber}. Waiter {WaiterName}.',
                'event_type' => 'NewOrder',
                'is_default' => false,
                'priority' => 1,
                'is_active' => true,
            ],
            [
                'branch_id' => $branchId,
                'template_name' => 'Swiggy Order',
                'template_text' => 'New Swiggy order received.',
                'event_type' => 'SwiggyOrder',
                'is_default' => true,
                'priority' => 2,
                'is_active' => true,
            ],
            [
                'branch_id' => $branchId,
                'template_name' => 'Zomato Order',
                'template_text' => 'New Zomato order received.',
                'event_type' => 'ZomatoOrder',
                'is_default' => true,
                'priority' => 2,
                'is_active' => true,
            ],
            [
                'branch_id' => $branchId,
                'template_name' => 'Collect Order',
                'template_text' => 'Waiter {WaiterName}, please collect order from kitchen.',
                'event_type' => 'CollectOrder',
                'is_default' => true,
                'priority' => 1,
                'is_active' => true,
            ],
            [
                'branch_id' => $branchId,
                'template_name' => 'Order Delayed',
                'template_text' => 'Warning. Table {TableNumber} order delayed by {DelayMinutes} minutes.',
                'event_type' => 'OrderDelayed',
                'is_default' => true,
                'priority' => 2,
                'is_active' => true,
            ],
            [
                'branch_id' => $branchId,
                'template_name' => 'Payment Success',
                'template_text' => 'Payment received for order {OrderNumber}.',
                'event_type' => 'PaymentSuccess',
                'is_default' => true,
                'priority' => 1,
                'is_active' => true,
            ],
            [
                'branch_id' => $branchId,
                'template_name' => 'KOT Ready',
                'template_text' => 'Kitchen order ticket is ready for order {OrderNumber}.',
                'event_type' => 'KotReady',
                'is_default' => true,
                'priority' => 1,
                'is_active' => true,
            ],
            [
                'branch_id' => $branchId,
                'template_name' => 'Order Ready',
                'template_text' => 'Table number {TableNumber}, order is ready. Please take it.',
                'event_type' => 'OrderReady',
                'is_default' => true,
                'priority' => 1,
                'is_active' => true,
            ],
            [
                'branch_id' => $branchId,
                'template_name' => 'Table Ready',
                'template_text' => 'Table {TableNumber} is ready.',
                'event_type' => 'TableReady',
                'is_default' => true,
                'priority' => 1,
                'is_active' => true,
            ],
        ];
    }

    /**
     * Substitute variables in template text.
     */
    public function substituteVariables(array $variables): string
    {
        $text = $this->template_text;

        foreach ($variables as $key => $value) {
            $value = trim((string) $value);

            // Leave the placeholder in place for blank values so the fragment
            // it belongs to is dropped below rather than spoken half-empty.
            if ($value === '') {
                continue;
            }

            $text = str_replace('{' . $key . '}', $value, $text);
        }

        return $this->dropUnresolvedFragments($text);
    }

    /**
     * Drop sentence fragments whose variables were never resolved.
     *
     * Announcements are read aloud, so an unresolved "{WaiterName}" would be
     * spoken literally. Orders legitimately arrive without a table (takeaway)
     * or without an assigned waiter, so the fragment is removed instead.
     */
    private function dropUnresolvedFragments(string $text): string
    {
        if (!str_contains($text, '{')) {
            return $text;
        }

        $fragments = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $kept = array_filter(
            $fragments,
            static fn (string $fragment): bool => !preg_match('/\{\w+\}/', $fragment)
        );

        if ($kept !== []) {
            return trim(implode(' ', $kept));
        }

        // Every fragment depended on a missing variable. Strip the placeholders
        // so the announcement still says something rather than nothing at all.
        $stripped = preg_replace('/\{\w+\}/', '', $text) ?? '';
        $stripped = preg_replace('/\s+/', ' ', $stripped) ?? '';
        $stripped = preg_replace('/\s+([.,!?])/', '$1', $stripped) ?? '';

        return trim($stripped);
    }

    /**
     * Extract variables from template text.
     */
    public function extractVariables(): array
    {
        preg_match_all('/\{(\w+)\}/', $this->template_text, $matches);
        return $matches[1] ?? [];
    }

    protected static function newFactory(): VoiceTemplateFactory
    {
        return VoiceTemplateFactory::new();
    }
}

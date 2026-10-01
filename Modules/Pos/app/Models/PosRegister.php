<?php

namespace Modules\Pos\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Pos\Database\Factories\PosRegisterFactory;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Printer\Models\Printer;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property string $name
 * @property string $note
 * @property string $code
 * @property int|null $last_session_id
 * @property PosSession|null $lastSession
 * @property int|null $invoice_printer_id
 * @property Printer|null $invoicePrinter
 * @property int|null $bill_printer_id
 * @property Printer|null $billPrinter
 * @property int|null $waiter_printer_id
 * @property Printer|null $waiterPrinter
 * @property int|null $delivery_printer_id
 * @property Printer|null $deliveryPrinter
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class PosRegister extends Model
{
    use HasFactory,
        HasSortBy,
        HasFilters,
        SoftDeletes,
        HasCreatedBy,
        HasBranch,
        HasTagsCache,
        Translatable,
        HasActiveStatus,
        HasActivityLog;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "name",
        "code",
        "note",
        "invoice_printer_id",
        "bill_printer_id",
        "waiter_printer_id",
        "delivery_printer_id",
        self::BRANCH_COLUMN_NAME,
        self::ACTIVE_COLUMN_NAME,
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name', 'note'];

    /**
     * Get a list of all/by branch pos registers.
     *
     * @param int|null $branchId
     * @param bool $withLastSession
     * @return Collection
     */
    public static function list(?int $branchId = null, bool $withLastSession = false): Collection
    {
        $resolver = function () use ($branchId, $withLastSession) {
            $registers = static::select('id', 'name', 'code', 'last_session_id')
                ->when(!is_null($branchId), fn($query) => $query->whereBranch($branchId))
                ->get();
            $sessions = $withLastSession
                ? PosSession::query()
                    ->whereIn('pos_register_id', $registers->pluck('id'))
                    ->where('status', PosSessionStatus::Open)
                    ->latest('opened_at')
                    ->get(['id', 'pos_register_id'])
                    ->keyBy('pos_register_id')
                : collect();

            return $registers->map(function (PosRegister $posRegister) use ($withLastSession, $sessions) {
                $data = [
                    'id' => $posRegister->id,
                    'name' => "$posRegister->name ($posRegister->code)",
                ];
                $session = $sessions->get($posRegister->id);
                if ($withLastSession && !is_null($session)) {
                    $data['session'] = [
                        "id" => $session->id,
                    ];
                }
                return $data;
            });
        };

        if ($withLastSession) {
            return $resolver();
        }

        return Cache::tags("pos_registers")
            ->rememberForever(
                makeCacheKey([
                    'pos_registers',
                    is_null($branchId) ? 'all' : "branch-$branchId",
                    $withLastSession ? 'with_last_session' : 'with_out_last_session',
                    'list'
                ]),
                $resolver
            );
    }

    /**
     * Get active session
     *
     * @param int $registerId
     * @param string|null $action
     * @return PosSession|null
     */
    public static function activeSession(int $registerId, ?string $action = null): ?PosSession
    {
        $posRegister = PosRegister::query()
            ->where('id', $registerId)
            ->firstOrFail();
        $session = PosSession::query()
            ->with("branch:id,currency")
            ->where('pos_register_id', $posRegister->id)
            ->where('status', PosSessionStatus::Open)
            ->latest('opened_at')
            ->first();

        abort_if(
            !is_null($action) && is_null($session),
            400,
            __("pos::messages.no_active_session", ["action" => $action])
        );

        return $session;
    }

    protected static function newFactory(): PosRegisterFactory
    {
        return PosRegisterFactory::new();
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            self::BRANCH_COLUMN_NAME,
            self::ACTIVE_COLUMN_NAME,
            "invoice_printer_id",
            "bill_printer_id",
        ];
    }

    /**
     * Scope a query to search across all fields.
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeSearch(Builder $query, string $value): void
    {
        $query->whereLikeTranslation('name', $value)
            ->orLike('code', $value);
    }

    /**
     * Get invoice printer
     *
     * @return BelongsTo
     */
    public function invoicePrinter(): BelongsTo
    {
        return $this->belongsTo(Printer::class, "invoice_printer_id");
    }

    /**
     * Get bill printer
     *
     * @return BelongsTo
     */
    public function billPrinter(): BelongsTo
    {
        return $this->belongsTo(Printer::class, "bill_printer_id");
    }

    /**
     * Get waiter printer
     *
     * @return BelongsTo
     */
    public function waiterPrinter(): BelongsTo
    {
        return $this->belongsTo(Printer::class, "waiter_printer_id");
    }

    /**
     * Get delivery printer
     *
     * @return BelongsTo
     */
    public function deliveryPrinter(): BelongsTo
    {
        return $this->belongsTo(Printer::class, "delivery_printer_id");
    }

    /**
     * Get last session
     *
     * @return BelongsTo
     */
    public function lastSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'last_session_id')
            ->withOutGlobalBranchPermission();
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
            "code",
            "invoice_printer_id",
            "bill_printer_id",
            self::ACTIVE_COLUMN_NAME,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            self::ACTIVE_COLUMN_NAME => "boolean",
        ];
    }
}

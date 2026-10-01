<?php

namespace Modules\Pos\Services\PosCashMovement;

use App\NexDine;
use DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Currency\Models\CurrencyRate;
use Modules\Pos\Enums\PosCashDirection;
use Modules\Pos\Enums\PosCashReason;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Models\PosCashMovement;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Models\PosSession;
use Modules\Support\GlobalStructureFilters;
use Modules\Support\Money;
use Throwable;

class PosCashMovementService implements PosCashMovementServiceInterface
{
    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with(["branch:id,name", "posRegister:id,name"])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): PosCashMovement
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return PosCashMovement::class;
    }

    /** @inheritDoc */
    public function show(int $id): PosCashMovement
    {
        return $this->getModel()
            ->query()
            ->with(["branch:id,name", "posRegister:id,name", "createdBy:id,name"])
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function label(): string
    {
        return __("pos::pos_cash_movements.pos_cash_movement");
    }

    /** @inheritDoc */
    public function sale(
        PosSession $session,
        float      $amount,
        ?int       $orderId = null,
        ?int       $paymentId = null,
        ?string    $reference = null,
        ?string    $notes = null,
    ): PosCashMovement
    {
        return $this->addMovement(
            session: $session,
            reason: PosCashReason::Sale,
            amount: $amount,
            direction: PosCashDirection::In,
            orderId: $orderId,
            paymentId: $paymentId,
            reference: $reference,
            notes: $notes,
        );
    }

    /**
     * Generic entry point (useful for admin tools or bulk import).
     * It records the movement and applies Rollups if needed.
     *
     * @param PosSession $session
     * @param PosCashReason $reason
     * @param float $amount
     * @param PosCashDirection $direction
     * @param int|null $orderId
     * @param int|null $paymentId
     * @param string|null $reference
     * @param string|null $notes
     * @return PosCashMovement
     * @throws Throwable
     */
    public function addMovement(
        PosSession       $session,
        PosCashReason    $reason,
        float            $amount,
        PosCashDirection $direction,
        ?int             $orderId = null,
        ?int             $paymentId = null,
        ?string          $reference = null,
        ?string          $notes = null,
    ): PosCashMovement
    {
        return DB::transaction(
            fn() => $this->storeMovement(
                session: $session,
                reason: $reason,
                direction: $direction,
                amount: $amount,
                orderId: $orderId,
                paymentId: $paymentId,
                reference: $reference,
                notes: $notes,
            )
        );
    }

    private function storeMovement(
        PosSession       $session,
        PosCashReason    $reason,
        PosCashDirection $direction,
        float            $amount,
        ?int             $orderId,
        ?int             $paymentId,
        ?string          $reference,
        ?string          $notes,
    ): PosCashMovement
    {
        // Get the last balance in this session
        $lastBalance = PosCashMovement::query()
            ->withOutGlobalBranchPermission()
            ->where('pos_session_id', $session->id)
            ->orderByDesc('id')
            ->first()?->balance_after ?? $session->opening_float;

        $balanceBefore = $lastBalance->amount();

        // Adjust balance depending on direction
        if ($direction === PosCashDirection::In) {
            $balanceAfter = $balanceBefore + $amount;
        } elseif ($direction === PosCashDirection::Out) {
            abort_if(
                $amount > $balanceBefore,
                400,
                __("pos::messages.insufficient_cash_balance", [
                    "amount" => (new Money($amount, $session->branch->currency))->format(),
                    "balance" => (new Money($balanceBefore, $session->branch->currency))->format(),
                ])
            );

            $balanceAfter = $balanceBefore - $amount;
        } else { // Adjust — no physical change
            $balanceAfter = $balanceBefore;
        }

        return PosCashMovement::query()
            ->create([
                'branch_id' => $session->branch_id,
                'pos_register_id' => $session->pos_register_id,
                'pos_session_id' => $session->id,
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'direction' => $direction,
                'reason' => $reason,
                'amount' => $amount,
                'currency' => $session->branch->currency,
                'currency_rate' => CurrencyRate::for($session->branch->currency),
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference' => $reference,
                'notes' => $notes,
            ]);
    }

    /** @inheritDoc */
    public function refundCash(
        PosSession $session,
        float      $amount,
        ?int       $orderId = null,
        ?int       $paymentId = null,
        ?string    $reference = null,
        ?string    $notes = null,
    ): PosCashMovement
    {
        return $this->addMovement(
            session: $session,
            reason: PosCashReason::Refund,
            amount: $amount,
            direction: PosCashDirection::Out,
            orderId: $orderId,
            paymentId: $paymentId,
            reference: $reference,
            notes: $notes
        );
    }

    /** @inheritDoc */
    public function payIn(
        PosSession $session,
        float      $amount,
        ?string    $reference = null,
        ?string    $notes = null,

    ): PosCashMovement
    {
        return $this->addMovement(
            session: $session,
            reason: PosCashReason::PayIn,
            amount: $amount,
            direction: PosCashDirection::In,
            reference: $reference,
            notes: $notes,
        );
    }

    /** @inheritDoc */
    public function payOut(
        PosSession $session,
        float      $amount,
        ?string    $reference = null,
        ?string    $notes = null,

    ): PosCashMovement
    {
        return $this->addMovement(
            session: $session,
            reason: PosCashReason::PayOut,
            amount: $amount,
            direction: PosCashDirection::Out,
            reference: $reference,
            notes: $notes,
        );
    }

    /** @inheritDoc */
    public function cashDrop(
        PosSession $session,
        float      $amount,
        ?string    $reference = null,
        ?string    $notes = null,

    ): PosCashMovement
    {
        return $this->addMovement(
            session: $session,
            reason: PosCashReason::CashDrop,
            amount: $amount,
            direction: PosCashDirection::Out,
            reference: $reference,
            notes: $notes,
        );
    }

    /** @inheritDoc */
    public function closingAdjust(
        PosSession $session,
        float      $amount,
        ?string    $reference = null,
        ?string    $notes = null,

    ): PosCashMovement
    {
        return $this->addMovement(
            session: $session,
            reason: PosCashReason::ClosingAdjust,
            amount: $amount,
            direction: PosCashDirection::Adjust,
            reference: $reference,
            notes: $notes,
        );
    }

    /** @inheritDoc */
    public function tipIn(
        PosSession $session,
        float      $amount,
        ?string    $reference = null,
        ?string    $notes = null,

    ): PosCashMovement
    {
        return $this->addMovement(
            session: $session,
            reason: PosCashReason::TipIn,
            amount: $amount,
            direction: PosCashDirection::In,
            reference: $reference,
            notes: $notes
        );
    }

    /** @inheritDoc */
    public function tipOut(
        PosSession $session,
        float      $amount,
        ?string    $reference = null,
        ?string    $notes = null,
    ): PosCashMovement
    {
        return $this->addMovement(
            session: $session,
            reason: PosCashReason::TipOut,
            amount: $amount,
            direction: PosCashDirection::Out,
            reference: $reference,
            notes: $notes,
        );
    }

    /** @inheritDoc */
    public function correction(
        PosSession       $session,
        float            $amount,
        PosCashDirection $direction,
        ?string          $reference = null,
        ?string          $notes = null,
    ): PosCashMovement
    {
        return $this->addMovement(
            session: $session,
            reason: PosCashReason::Correction,
            amount: $amount,
            direction: $direction,
            reference: $reference,
            notes: $notes,
        );
    }

    /** @inheritDoc */
    public function getStructureFilters(?int $branchId = null, ?int $sessionId = null): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        return [
            ...(is_null($sessionId) ? [
                ...(is_null($branchFilter) ? [] : [$branchFilter]),
                [
                    "key" => 'pos_register_id',
                    "label" => __('pos::pos_cash_movements.filters.pos_register'),
                    "type" => 'select',
                    "options" => !is_null($branchId) ? PosRegister::list($branchId) : [],
                    "depends" => "branch_id"
                ],
            ] : []),
            [
                "key" => 'direction',
                "label" => __('pos::pos_cash_movements.filters.direction'),
                "type" => 'select',
                "options" => PosCashDirection::toArrayTrans(),
            ],
            [
                "key" => 'reason',
                "label" => __('pos::pos_cash_movements.filters.reason'),
                "type" => 'select',
                "options" => PosCashReason::toArrayTrans(),
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function store(array $data): PosCashMovement
    {
        return $this->addMovement(
            session: $this->getPosActiveSession(
                $data['pos_register_id'],
                __("pos::pos_cash_movements.pos_cash_movement")
            ),
            reason: PosCashReason::from($data["reason"]),
            amount: $data['amount'],
            direction: PosCashDirection::from($data["direction"]),
            reference: $data['reference'] ?? null,
            notes: $data['notes'] ?? null,
        );
    }

    /** @inheritDoc */
    public function getPosActiveSession(int $posRegisterId, ?string $action = null): ?PosSession
    {
        $posRegister = PosRegister::query()
            ->with(["lastSession" => fn($query) => $query->with("branch:id,currency")
                ->where('status', PosSessionStatus::Open)])
            ->where('id', $posRegisterId)
            ->firstOrFail();

        abort_if(
            !is_null($action) && is_null($posRegister->lastSession),
            400,
            __("pos::messages.no_active_session", ["action" => $action])
        );

        return $posRegister->lastSession;
    }
}

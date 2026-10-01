<?php

namespace Modules\Pos\Services\PosCashMovement;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Pos\Enums\PosCashDirection;
use Modules\Pos\Models\PosCashMovement;
use Modules\Pos\Models\PosSession;
use Throwable;

interface PosCashMovementServiceInterface
{
    /**
     * Display a listing of the resource.
     *
     * @param array $filters
     * @param array $sorts
     * @return LengthAwarePaginator
     */
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator;

    /**
     * Show the specified resource.
     *
     * @param int $id
     * @return PosCashMovement
     * @throws ModelNotFoundException
     */
    public function show(int $id): PosCashMovement;

    /**
     * Label for the resource.
     *
     * @return string
     */
    public function label(): string;

    /**
     * Model for the resource.
     *
     * @return string
     */
    public function model(): string;

    /**
     * Get a new instance of the model.
     *
     * @return PosCashMovement
     */
    public function getModel(): PosCashMovement;

    /**
     * Cash sale -> IN
     *
     * @param PosSession $session
     * @param float $amount
     * @param int|null $orderId
     * @param int|null $paymentId
     * @param string|null $reference
     * @param string|null $notes
     * @return PosCashMovement
     * @throws Throwable
     */
    public function sale(
        PosSession $session,
        float      $amount,
        ?int       $orderId = null,
        ?int       $paymentId = null,
        ?string    $reference = null,
        ?string    $notes = null,
    ): PosCashMovement;

    /**
     * Cash refund -> OUT
     *
     * @param PosSession $session
     * @param float $amount
     * @param int|null $orderId
     * @param int|null $paymentId
     * @param string|null $reference
     * @param string|null $notes
     * @return PosCashMovement
     * @throws Throwable
     */
    public function refundCash(
        PosSession $session,
        float      $amount,
        ?int       $orderId = null,
        ?int       $paymentId = null,
        ?string    $reference = null,
        ?string    $notes = null,
    ): PosCashMovement;

    /**
     * Pay-in (top-up) -> IN
     *
     * @param PosSession $session
     * @param float $amount
     * @param string|null $reference
     * @param string|null $notes
     * @return PosCashMovement
     * @throws Throwable
     */
    public function payIn(
        PosSession $session,
        float      $amount,
        ?string    $reference = null,
        ?string    $notes = null,

    ): PosCashMovement;

    /**
     * Pay-out (supplier/petty cash) -> OUT
     *
     * @param PosSession $session
     * @param float $amount
     * @param string|null $reference
     * @param string|null $notes
     * @return PosCashMovement
     * @throws Throwable
     */
    public function payOut(
        PosSession $session,
        float      $amount,
        ?string    $reference = null,
        ?string    $notes = null,

    ): PosCashMovement;

    /**
     * Cash drop to safe -> OUT
     *
     * @param PosSession $session
     * @param float $amount
     * @param string|null $reference
     * @param string|null $notes
     * @return PosCashMovement
     * @throws Throwable
     */
    public function cashDrop(
        PosSession $session,
        float      $amount,
        ?string    $reference = null,
        ?string    $notes = null,

    ): PosCashMovement;

    /**
     * Closing adjust -> ADJUST (no physical in/out)
     *
     * @param PosSession $session
     * @param float $amount
     * @param string|null $reference
     * @param string|null $notes
     * @return PosCashMovement
     * @throws Throwable
     */
    public function closingAdjust(
        PosSession $session,
        float      $amount,
        ?string    $reference = null,
        ?string    $notes = null,

    ): PosCashMovement;

    /**
     * Tip in -> IN
     *
     * @param PosSession $session
     * @param float $amount
     * @param string|null $reference
     * @param string|null $notes
     * @return PosCashMovement
     * @throws Throwable
     */
    public function tipIn(
        PosSession $session,
        float      $amount,
        ?string    $reference = null,
        ?string    $notes = null,

    ): PosCashMovement;

    /**
     * Tip out -> OUT
     *
     * @param PosSession $session
     * @param float $amount
     * @param string|null $reference
     * @param string|null $notes
     * @return PosCashMovement
     * @throws Throwable
     */
    public function tipOut(
        PosSession $session,
        float      $amount,
        ?string    $reference = null,
        ?string    $notes = null,
    ): PosCashMovement;

    /**
     * Manual correction (physical add/remove OR neutral adjust)
     *
     * @param PosSession $session
     * @param float $amount
     * @param PosCashDirection $direction
     * @param string|null $reference
     * @param string|null $notes
     * @return PosCashMovement
     * @throws Throwable
     */
    public function correction(
        PosSession       $session,
        float            $amount,
        PosCashDirection $direction,
        ?string          $reference = null,
        ?string          $notes = null,
    ): PosCashMovement;

    /**
     * Get structure filters for frontend
     *
     * @param int|null $branchId
     * @param int|null $sessionId
     * @return array
     */
    public function getStructureFilters(?int $branchId = null, ?int $sessionId = null): array;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return PosCashMovement
     * @throws Throwable
     */
    public function store(array $data): PosCashMovement;

    /**
     * Get pos active session
     *
     * @param int $posRegisterId
     * @param string|null $action
     * @return PosSession|null
     */
    public function getPosActiveSession(int $posRegisterId, ?string $action = null): ?PosSession;
}

<?php

namespace Modules\Pos\Services\PosSession;

use App\NexDine;
use DB;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Events\ClosePosSession;
use Modules\Pos\Events\OpenPosSession;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Models\PosSession;
use Modules\Support\GlobalStructureFilters;

class PosSessionService implements PosSessionServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("pos::pos_sessions.pos_session");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with([
                "branch:id,name,currency",
                "openedBy",
                "closedBy",
                "posRegister"
            ])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): PosSession
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return PosSession::class;
    }

    /** @inheritDoc */
    public function show(int $id): PosSession
    {
        return $this->getModel()
            ->query()
            ->with([
                "branch:id,name,currency",
                "openedBy",
                "closedBy",
                "posRegister"
            ])
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|PosSession
    {
        return $this->getModel()
            ->query()
            ->with(["openedBy", "closedBy", "branch"])
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function open(array $data): PosSession
    {
        return DB::transaction(function () use ($data) {
            $register = PosRegister::query()
                ->whereBranch($data['branch_id'])
                ->findOrFail($data['pos_register_id']);

            $existing = PosSession::query()
                ->whereBranch($data['branch_id'])
                ->where('pos_register_id', $register->id)
                ->where('status', PosSessionStatus::Open)
                ->first();

            if ($existing) {
                PosRegister::query()
                    ->withOutGlobalBranchPermission()
                    ->whereKey($register->id)
                    ->update(['last_session_id' => $existing->id]);
                Cache::tags('pos_registers')->flush();

                return $existing;
            }

            $session = PosSession::query()
                ->create([
                    'branch_id' => $data['branch_id'],
                    'pos_register_id' => $register->id,
                    'opened_by' => auth()->id(),
                    'status' => PosSessionStatus::Open,
                    'opened_at' => now(),
                    'opening_float' => $data['opening_float'] ?? 0,
                    'notes' => $data['notes'] ?? null,
                ]);

            event(new OpenPosSession($session));
            PosRegister::query()
                ->withOutGlobalBranchPermission()
                ->whereKey($register->id)
                ->update(['last_session_id' => $session->id]);
            Cache::tags('pos_registers')->flush();

            return $session;
        });
    }

    /** @inheritDoc */
    public function close(int $id, array $data): PosSession
    {
        /** @var PosSession $session */
        $session = $this->findOrFail($id);

        return DB::transaction(function () use ($session, $data) {

            abort_if($session->status !== PosSessionStatus::Open, 400, __('pos::messages.session_closed'));

            $session->declared_cash = $data['declared_cash'];

            try {
                $summary = $session->calculateClosingSummary();
            } catch (Exception $exception) {
                throw ValidationException::withMessages([
                    "declared_cash" => $exception->getMessage(),
                ]);
            }

            $session->update([
                'closed_by' => auth()->id(),
                'closed_at' => now(),
                'status' => PosSessionStatus::Closed,
                'declared_cash' => $data['declared_cash'],
                ...$summary
            ]);

            event(new ClosePosSession($session));

            return $session;
        });
    }


    /** @inheritDoc */
    public function getStructureFilters(?int $branchId = null): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                "key" => 'pos_register_id',
                "label" => __('pos::pos_sessions.filters.pos_register'),
                "type" => 'select',
                "options" => !is_null($branchId) ? PosRegister::list($branchId) : [],
                "depends" => "branch_id"
            ],
            [
                "key" => 'status',
                "label" => __('pos::pos_sessions.filters.status'),
                "type" => 'select',
                "options" => PosSessionStatus::toArrayTrans()
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }


    /** @inheritDoc */
    public function getFormMeta(?int $branchId = null): array
    {
        if (is_null($branchId)) {
            return [
                "branches" => Branch::list(),
            ];
        } else {
            return [
                "pos_registers" => PosRegister::list($branchId),
            ];
        }
    }
}

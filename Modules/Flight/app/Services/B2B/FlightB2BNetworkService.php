<?php

namespace Modules\Flight\Services\B2B;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Flight\Enums\B2BStatus;
use Modules\Flight\Enums\CommissionBasis;
use Modules\Flight\Enums\CommissionCalculationType;
use Modules\Flight\Enums\CommissionRecipientTier;
use Modules\Flight\Enums\HolderType;
use Modules\Flight\Models\FlightAgent;
use Modules\Flight\Models\FlightB2BWallet;
use Modules\Flight\Models\FlightCommissionRule;
use Modules\Flight\Models\FlightDistributor;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Models\FlightInventoryAllocation;
use Modules\Flight\Services\Inventory\FlightInventoryAllocationEngine;
use Modules\Saas\Models\Tenant;
use RuntimeException;

class FlightB2BNetworkService
{
    public function __construct(
        protected FlightInventoryAllocationEngine $allocationEngine
    ) {}

    /**
     * Create a new Distributor under a Tenant.
     */
    public function createDistributor(array $data, ?int $tenantId = null): FlightDistributor
    {
        $tenantId ??= $data['tenant_id'] ?? auth()->user()?->tenant_id;

        if (! $tenantId) {
            throw new RuntimeException('Tenant ID is required to create a distributor.');
        }

        return DB::transaction(function () use ($data, $tenantId) {
            $code = strtoupper($data['code'] ?? 'DIST-' . Str::random(6));

            $distributor = FlightDistributor::create([
                'tenant_id'        => $tenantId,
                'user_id'          => $data['user_id'] ?? null,
                'name'             => $data['name'],
                'code'             => $code,
                'company_name'     => $data['company_name'] ?? null,
                'email'            => $data['email'] ?? (strtolower($code) . '@distributor.test'),
                'phone'            => $data['phone'] ?? null,
                'status'           => B2BStatus::ACTIVE->value,
                'credit_limit'     => (float) ($data['credit_limit'] ?? 0.0),
                'credit_balance'   => (float) ($data['credit_balance'] ?? 0.0),
                'commission_rate'  => (float) ($data['commission_rate'] ?? 0.0),
                'allow_sub_agents' => $data['allow_sub_agents'] ?? true,
                'max_agents'       => (int) ($data['max_agents'] ?? 50),
                'notes'            => $data['notes'] ?? null,
            ]);

            // Initialize B2B wallet
            FlightB2BWallet::create([
                'tenant_id'    => $tenantId,
                'owner_type'   => 'DISTRIBUTOR',
                'owner_id'     => $distributor->id,
                'currency'     => $data['currency'] ?? 'INR',
                'balance'      => (float) ($data['initial_balance'] ?? 0.0),
                'credit_limit' => (float) ($data['credit_limit'] ?? 0.0),
                'credit_used'  => 0.0,
                'is_active'    => true,
            ]);

            return $distributor->fresh(['wallet']);
        });
    }

    /**
     * Create an Agent under a Distributor.
     */
    public function createAgent(FlightDistributor $distributor, array $data): FlightAgent
    {
        if (! $distributor->isActive()) {
            throw new RuntimeException("Cannot create agent under a non-active distributor [{$distributor->name}].");
        }

        if (! $distributor->allow_sub_agents) {
            throw new RuntimeException("Distributor [{$distributor->name}] is not permitted to register sub-agents.");
        }

        if ($distributor->agents()->count() >= $distributor->max_agents) {
            throw new RuntimeException("Distributor [{$distributor->name}] has reached its agent capacity limit ({$distributor->max_agents}).");
        }

        return DB::transaction(function () use ($distributor, $data) {
            $code = strtoupper($data['code'] ?? 'AGT-' . Str::random(6));

            $agent = FlightAgent::create([
                'tenant_id'             => $distributor->tenant_id,
                'flight_distributor_id' => $distributor->id,
                'user_id'               => $data['user_id'] ?? null,
                'name'                  => $data['name'],
                'code'                  => $code,
                'agency_name'           => $data['agency_name'] ?? null,
                'iata_number'           => $data['iata_number'] ?? null,
                'email'                 => $data['email'] ?? (strtolower($code) . '@agent.test'),
                'phone'                 => $data['phone'] ?? null,
                'status'                => B2BStatus::ACTIVE->value,
                'credit_limit'          => (float) ($data['credit_limit'] ?? 0.0),
                'credit_balance'        => (float) ($data['credit_balance'] ?? 0.0),
                'commission_rate'       => (float) ($data['commission_rate'] ?? 0.0),
                'notes'                 => $data['notes'] ?? null,
            ]);

            // Initialize agent wallet
            FlightB2BWallet::create([
                'tenant_id'    => $distributor->tenant_id,
                'owner_type'   => 'AGENT',
                'owner_id'     => $agent->id,
                'currency'     => $data['currency'] ?? 'INR',
                'balance'      => (float) ($data['initial_balance'] ?? 0.0),
                'credit_limit' => (float) ($data['credit_limit'] ?? 0.0),
                'credit_used'  => 0.0,
                'is_active'    => true,
            ]);

            return $agent->fresh(['wallet', 'distributor']);
        });
    }

    /**
     * Allocate inventory from Tenant to Distributor.
     */
    public function allocateToDistributor(
        FlightDistributor $distributor,
        FlightInventory $inventory,
        string $cabin,
        int $seats,
        ?int $actorId = null
    ): FlightInventoryAllocation {
        return $this->allocationEngine->allocate(
            inventory:      $inventory,
            cabin:          $cabin,
            fromType:       HolderType::TENANT,
            fromId:         $distributor->tenant_id,
            toType:         HolderType::DISTRIBUTOR,
            toId:           $distributor->id,
            seats:          $seats,
            actorId:        $actorId
        );
    }

    /**
     * Allocate inventory from Distributor to Agent.
     */
    public function allocateToAgent(
        FlightDistributor $distributor,
        FlightAgent $agent,
        FlightInventory $inventory,
        string $cabin,
        int $seats,
        ?int $actorId = null
    ): FlightInventoryAllocation {
        if ($agent->flight_distributor_id !== $distributor->id) {
            throw new RuntimeException("Agent [{$agent->code}] does not belong to Distributor [{$distributor->code}].");
        }

        if (! $distributor->isActive()) {
            throw new RuntimeException("Suspended distributor cannot allocate inventory.");
        }

        return $this->allocationEngine->allocate(
            inventory:      $inventory,
            cabin:          $cabin,
            fromType:       HolderType::DISTRIBUTOR,
            fromId:         $distributor->id,
            toType:         HolderType::AGENT,
            toId:           $agent->id,
            seats:          $seats,
            actorId:        $actorId
        );
    }

    /**
     * Configure a commission rule targeting a distributor or agent.
     */
    public function configureCommission(
        CommissionRecipientTier $tier,
        int $recipientId,
        float $percentage,
        float $fixedAmount = 0.0,
        ?int $tenantId = null
    ): FlightCommissionRule {
        $tenantId ??= auth()->user()?->tenant_id;

        return FlightCommissionRule::create([
            'tenant_id'         => $tenantId,
            'name'              => "{$tier->label()} Commission #{$recipientId}",
            'recipient_tier'    => $tier->value,
            'recipient_id'      => $recipientId,
            'calculation_type'  => $fixedAmount > 0 ? ($percentage > 0 ? CommissionCalculationType::HYBRID->value : CommissionCalculationType::FIXED->value) : CommissionCalculationType::PERCENTAGE->value,
            'calculation_basis' => CommissionBasis::BASE_FARE->value,
            'percentage'        => $percentage,
            'fixed_amount'      => $fixedAmount,
            'priority'          => 20,
            'is_active'         => true,
        ]);
    }

    /**
     * Build the hierarchy tree visualization for a tenant: Tenant -> Distributors -> Agents.
     */
    public function getHierarchyTree(?int $tenantId = null): array
    {
        $tenantId ??= auth()->user()?->tenant_id;
        $tenant = Tenant::findOrFail($tenantId);

        $distributors = FlightDistributor::where('tenant_id', $tenantId)
            ->with(['agents.wallet', 'wallet', 'inventoryBalances'])
            ->get();

        $tree = [
            'id'           => $tenant->id,
            'name'         => $tenant->name,
            'slug'         => $tenant->slug,
            'tier'         => 'TENANT',
            'distributors' => $distributors->map(function (FlightDistributor $dist) {
                return [
                    'id'               => $dist->id,
                    'name'             => $dist->name,
                    'code'             => $dist->code,
                    'tier'             => 'DISTRIBUTOR',
                    'status'           => $dist->status->value,
                    'credit_limit'     => $dist->credit_limit,
                    'available_credit' => $dist->wallet?->available_credit ?? 0.0,
                    'agents_count'     => $dist->agents->count(),
                    'total_allocated'  => $dist->inventoryBalances->sum('total_allocated'),
                    'available_seats'  => $dist->inventoryBalances->sum('available'),
                    'agents'           => $dist->agents->map(function (FlightAgent $agt) {
                        return [
                            'id'               => $agt->id,
                            'name'             => $agt->name,
                            'code'             => $agt->code,
                            'tier'             => 'AGENT',
                            'status'           => $agt->status->value,
                            'is_operational'   => $agt->isOperational(),
                            'credit_limit'     => $agt->credit_limit,
                            'available_credit' => $agt->wallet?->available_credit ?? 0.0,
                        ];
                    }),
                ];
            }),
        ];

        return $tree;
    }
}

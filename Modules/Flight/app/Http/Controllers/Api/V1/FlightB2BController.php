<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Enums\B2BStatus;
use Modules\Flight\Enums\BookingStatus;
use Modules\Flight\Enums\HolderType;
use Modules\Flight\Enums\PaymentStatus;
use Modules\Flight\Models\FlightAgent;
use Modules\Flight\Models\FlightB2BWallet;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightDistributor;
use Modules\Flight\Models\FlightFareQuote;
use Modules\Flight\Models\FlightInventoryBalance;
use Modules\Flight\Services\B2B\FlightB2BNetworkService;
use Modules\Flight\Services\Booking\FlightBookingService;
use Modules\Flight\Services\Payment\FlightPaymentService;
use Modules\Flight\Services\Servicing\FlightServicingService;
use Modules\Flight\Services\Wallet\FlightWalletLedgerService;
use Modules\Saas\Models\Tenant;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;
use RuntimeException;

class FlightB2BController extends Controller
{
    public function __construct(
        protected FlightB2BNetworkService $service,
        protected FlightWalletLedgerService $walletService,
        protected FlightBookingService $bookingService,
        protected FlightPaymentService $paymentService,
        protected FlightServicingService $servicingService,
    ) {}

    protected function resolveTenant(Request $request): ?Tenant
    {
        $tenantId = $request->user()?->tenant_id ?? auth()->user()?->tenant_id;
        if (! $tenantId && $request->hasHeader('X-Tenant-ID')) {
            $tenantId = (int) $request->header('X-Tenant-ID');
        }
        if (! $tenantId && $request->filled('tenant_id')) {
            $tenantId = (int) $request->input('tenant_id');
        }

        if ($tenantId) {
            return Tenant::find($tenantId);
        }

        return null;
    }

    /**
     * Who is calling, decided only from the signed-in account:
     *  - AGENT:       the login linked to a B2B agent (flight_agents.user_id)
     *  - DISTRIBUTOR: the login linked to a distributor (legacy tier)
     *  - TENANT:      the agency's own administrators
     * Request headers or body fields never choose the role or the agent, so
     * one agent cannot act as (and spend the wallet of) another.
     */
    protected function resolveB2BActor(Request $request, ?Tenant $tenant): array
    {
        $user = $request->user();

        // 1. Check if linked to FlightAgent
        $agent = null;
        if ($user) {
            $agent = FlightAgent::where('user_id', $user->id)
                ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))
                ->first();
        }

        if ($agent) {
            return [
                'role'        => 'AGENT',
                'agent'       => $agent,
                'distributor' => $agent->distributor,
                'tenant'      => $tenant,
                'user'        => $user,
            ];
        }

        // 2. Check if linked to FlightDistributor
        $distributor = null;
        if ($user) {
            $distributor = FlightDistributor::where('user_id', $user->id)
                ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))
                ->first();
        }

        if ($distributor) {
            return [
                'role'        => 'DISTRIBUTOR',
                'agent'       => null,
                'distributor' => $distributor,
                'tenant'      => $tenant,
                'user'        => $user,
            ];
        }

        // 3. The agency's own administrators. Anyone else (customers, a
        // flight_agent login not yet linked to an agent record) is refused.
        if (! $this->isTenantAdmin($user)) {
            return [
                'role'        => 'UNAUTHORIZED',
                'agent'       => null,
                'distributor' => null,
                'tenant'      => $tenant,
                'user'        => $user,
            ];
        }

        return [
            'role'        => 'TENANT',
            'agent'       => null,
            'distributor' => null,
            'tenant'      => $tenant,
            'user'        => $user,
        ];
    }

    /** Agency administrators: platform super admin and the tenant's admin roles. */
    protected function isTenantAdmin($user): bool
    {
        return \Modules\Flight\Http\Middleware\EnsureFlightAgencyAdmin::allows($user);
    }

    /** Managing B2B partners (create, suspend, credit) is for agency admins only. */
    protected function denyUnlessTenantAdmin(Request $request): ?JsonResponse
    {
        return $this->isTenantAdmin($request->user())
            ? null
            : ApiResponse::errors(null, 'Only agency administrators can manage B2B partners.', 403);
    }

    /**
     * Whether a B2B agent / distributor may act on (pay, cancel) a booking.
     * Only bookings stamped with their own id qualify; website (B2C) and other
     * partners' bookings carry no matching id and are refused.
     */
    protected function actorOwnsBooking(array $actor, FlightBooking $booking): bool
    {
        $meta = $booking->meta ?? [];

        return match ($actor['role']) {
            'AGENT'       => (int) ($meta['agent_id'] ?? 0) === (int) $actor['agent']->id,
            'DISTRIBUTOR' => (int) ($meta['distributor_id'] ?? 0) === (int) $actor['distributor']->id,
            'TENANT'      => true,
            default       => false,
        };
    }

    // ── PHASE 20: B2B BOOKING WORKSPACE ──────────────────────────────────────

    /**
     * Role-tailored B2B Workspace KPI and permissions profile.
     * GET /api/v1/flight/b2b/workspace
     */
    public function workspace(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant($request);
        if (! $tenant) {
            return ApiResponse::errors(null, 'Tenant context required.', 400);
        }

        $actor = $this->resolveB2BActor($request, $tenant);
        $role = $actor['role'];
        if ($role === 'UNAUTHORIZED') {
            return ApiResponse::errors(null, 'User is not authorized to access B2B workspace.', 403);
        } // 'TENANT', 'DISTRIBUTOR', or 'AGENT'
        $agent = $actor['agent'];
        $distributor = $actor['distributor'];
        $user = $actor['user'];

        // 1. Entity & Wallet
        $walletData = null;
        $entityData = null;

        if ($role === 'AGENT') {
            $wallet = $this->walletService->getOrCreateAgentWallet($agent);
            $walletData = [
                'id'               => $wallet->id,
                'account_number'   => $wallet->account_identifier,
                'currency'         => $wallet->currency,
                'balance'          => (float) $wallet->balance,
                'credit_limit'     => (float) $wallet->credit_limit,
                'credit_used'      => (float) $wallet->credit_used,
                'available_credit' => (float) $wallet->available_credit,
                'purchasing_power' => (float) $wallet->purchasing_power,
                'is_active'        => (bool) $wallet->is_active,
            ];
            $entityData = [
                'id'              => $agent->id,
                'name'            => $agent->name,
                'code'            => $agent->code,
                'agency_name'     => $agent->agency_name,
                'iata_number'     => $agent->iata_number,
                'email'           => $agent->email,
                'phone'           => $agent->phone,
                'status'          => $agent->status->value,
                'is_operational'  => $agent->isOperational(),
                'commission_rate' => (float) $agent->commission_rate,
                'distributor'     => $distributor ? [
                    'id'   => $distributor->id,
                    'name' => $distributor->name,
                    'code' => $distributor->code,
                ] : null,
            ];
        } elseif ($role === 'DISTRIBUTOR') {
            $wallet = $this->walletService->getOrCreateDistributorWallet($distributor);
            $walletData = [
                'id'               => $wallet->id,
                'account_number'   => $wallet->account_identifier,
                'currency'         => $wallet->currency,
                'balance'          => (float) $wallet->balance,
                'credit_limit'     => (float) $wallet->credit_limit,
                'credit_used'      => (float) $wallet->credit_used,
                'available_credit' => (float) $wallet->available_credit,
                'purchasing_power' => (float) $wallet->purchasing_power,
                'is_active'        => (bool) $wallet->is_active,
            ];
            $entityData = [
                'id'               => $distributor->id,
                'name'             => $distributor->name,
                'code'             => $distributor->code,
                'company_name'     => $distributor->company_name,
                'email'            => $distributor->email,
                'phone'            => $distributor->phone,
                'status'           => $distributor->status->value,
                'is_active'        => $distributor->isActive(),
                'commission_rate'  => (float) $distributor->commission_rate,
                'agents_count'     => $distributor->agents()->count(),
                'allow_sub_agents' => (bool) $distributor->allow_sub_agents,
            ];
        } else {
            // TENANT
            $wallet = $this->walletService->getOrCreateTenantWallet($tenant->id);
            $walletData = [
                'id'               => $wallet->id,
                'account_number'   => $wallet->account_identifier,
                'currency'         => $wallet->currency,
                'balance'          => (float) $wallet->balance,
                'credit_limit'     => (float) $wallet->credit_limit,
                'credit_used'      => (float) $wallet->credit_used,
                'available_credit' => (float) $wallet->available_credit,
                'purchasing_power' => (float) $wallet->purchasing_power,
                'is_active'        => (bool) $wallet->is_active,
            ];
            $entityData = [
                'id'                 => $tenant->id,
                'name'               => $tenant->name,
                'slug'               => $tenant->slug,
                'distributors_count' => FlightDistributor::where('tenant_id', $tenant->id)->count(),
                'agents_count'       => FlightAgent::where('tenant_id', $tenant->id)->count(),
            ];
        }

        // 2. Inventory Summary
        $inventoryQuery = FlightInventoryBalance::withoutGlobalTenant()
            ->where('tenant_id', $tenant->id);
        if ($role === 'AGENT') {
            $inventoryQuery->where('holder_type', HolderType::AGENT->value)
                ->where('holder_id', $agent->id);
        } elseif ($role === 'DISTRIBUTOR') {
            $inventoryQuery->where('holder_type', HolderType::DISTRIBUTOR->value)
                ->where('holder_id', $distributor->id);
        }

        $balances = $inventoryQuery->get();
        $inventorySummary = [
            'total_allocations' => $balances->count(),
            'total_seats'       => (int) $balances->sum('total_allocated'),
            'available_seats'   => (int) $balances->sum('available'),
            'booked_seats'      => (int) $balances->sum('booked'),
            'reserved_seats'    => (int) $balances->sum('reserved'),
            'frozen_seats'      => (int) $balances->sum('frozen'),
        ];

        // 3. Bookings Summary
        $bookingsQuery = FlightBooking::where('tenant_id', $tenant->id);
        if ($role === 'AGENT') {
            $bookingsQuery->where(function ($q) use ($agent, $user) {
                $q->where('meta->agent_id', $agent->id)
                  ->orWhere('user_id', $user?->id ?? 0);
            });
        } elseif ($role === 'DISTRIBUTOR') {
            $bookingsQuery->where('meta->distributor_id', $distributor->id);
        } else {
            // The agency's view: bookings made by its B2B agents, not website ones.
            $bookingsQuery->whereNotNull('meta->agent_id');
        }

        $allBookings = (clone $bookingsQuery)->get();
        $bookingsSummary = [
            'total'           => $allBookings->count(),
            'confirmed'       => $allBookings->where('booking_status', BookingStatus::CONFIRMED)->count(),
            'ticketed'        => $allBookings->filter(fn ($b) => $b->isTicketed())->count(),
            'cancelled'       => $allBookings->where('booking_status', BookingStatus::CANCELLED)->count(),
            'payment_pending' => $allBookings->where('booking_status', BookingStatus::PAYMENT_PENDING)->count(),
            'total_amount'    => round((float) $allBookings->sum('total_amount'), 2),
            'currency'        => $allBookings->first()?->currency ?? 'INR',
        ];

        // 4. Financial & Commission Summary (Strict Role Filtering)
        $financialSummary = [];
        if ($role === 'AGENT') {
            $agentCommissionTotal = 0.0;
            foreach ($allBookings as $b) {
                if ($b->booking_status === BookingStatus::CONFIRMED || $b->isTicketed()) {
                    $agentCommissionTotal += (float) ($b->meta['agent_commission'] ?? 0);
                }
            }
            $financialSummary = [
                'agent_commission_earned' => round($agentCommissionTotal, 2),
                'total_booking_volume'    => $bookingsSummary['total_amount'],
                'currency'                => $bookingsSummary['currency'],
            ];
        } elseif ($role === 'DISTRIBUTOR') {
            $distCommissionTotal = 0.0;
            $subAgentCommissionTotal = 0.0;
            foreach ($allBookings as $b) {
                if ($b->booking_status === BookingStatus::CONFIRMED || $b->isTicketed()) {
                    $distCommissionTotal += (float) ($b->meta['distributor_commission'] ?? 0);
                    $subAgentCommissionTotal += (float) ($b->meta['agent_commission'] ?? 0);
                }
            }
            $financialSummary = [
                'distributor_commission_earned' => round($distCommissionTotal, 2),
                'sub_agent_commissions_earned'  => round($subAgentCommissionTotal, 2),
                'total_commissions'             => round($distCommissionTotal + $subAgentCommissionTotal, 2),
                'total_booking_volume'          => $bookingsSummary['total_amount'],
                'currency'                      => $bookingsSummary['currency'],
            ];
        } else {
            // TENANT
            $distCommissionTotal = 0.0;
            $agentCommissionTotal = 0.0;
            foreach ($allBookings as $b) {
                if ($b->booking_status === BookingStatus::CONFIRMED || $b->isTicketed()) {
                    $distCommissionTotal += (float) ($b->meta['distributor_commission'] ?? 0);
                    $agentCommissionTotal += (float) ($b->meta['agent_commission'] ?? 0);
                }
            }
            // Real figures only: what agents booked and what the agency paid
            // them in commission. (A made-up "8% revenue" used to be shown.)
            $financialSummary = [
                'total_booking_volume'     => $bookingsSummary['total_amount'],
                'distributor_commissions'  => round($distCommissionTotal, 2),
                'agent_commissions'        => round($agentCommissionTotal, 2),
                'total_commissions'        => round($distCommissionTotal + $agentCommissionTotal, 2),
                'net_after_commissions'    => round($bookingsSummary['total_amount'] - $distCommissionTotal - $agentCommissionTotal, 2),
                'currency'                 => $bookingsSummary['currency'],
            ];
        }

        // 5. Role Capabilities / Permissions Map
        $permissions = [
            'can_view_tenant_revenue'    => ($role === 'TENANT'),
            'can_view_all_distributors'  => ($role === 'TENANT'),
            'can_view_all_agents'        => in_array($role, ['TENANT', 'DISTRIBUTOR'], true),
            'can_manage_credit_limit'    => ($role === 'TENANT'),
            'can_manage_agents'          => in_array($role, ['TENANT', 'DISTRIBUTOR'], true),
            'can_book_wallet'            => true,
            'can_cancel_own_booking'     => true,
            'can_view_agent_commission'  => true,
            'can_view_dist_commission'   => in_array($role, ['TENANT', 'DISTRIBUTOR'], true),
        ];

        return ApiResponse::success([
            'role'               => $role,
            'entity'             => $entityData,
            'wallet'             => $walletData,
            'inventory_summary'  => $inventorySummary,
            'bookings_summary'   => $bookingsSummary,
            'financial_summary'  => $financialSummary,
            'permissions'        => $permissions,
        ]);
    }

    /**
     * Role-scoped flight bookings history.
     * GET /api/v1/flight/b2b/bookings
     */
    public function b2bBookings(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant($request);
        if (! $tenant) {
            return ApiResponse::errors(null, 'Tenant context required.', 400);
        }

        $actor = $this->resolveB2BActor($request, $tenant);
        $role = $actor['role'];
        if ($role === 'UNAUTHORIZED') {
            return ApiResponse::errors(null, 'User is not authorized to access B2B workspace.', 403);
        }
        $agent = $actor['agent'];
        $distributor = $actor['distributor'];
        $user = $actor['user'];

        $query = FlightBooking::where('tenant_id', $tenant->id)
            ->with(['passengers', 'tickets', 'payments'])
            ->latest('id');

        if ($role === 'AGENT') {
            $query->where(function ($q) use ($agent, $user) {
                $q->where('meta->agent_id', $agent->id)
                  ->orWhere('user_id', $user?->id ?? 0);
            });
        } elseif ($role === 'DISTRIBUTOR') {
            $query->where('meta->distributor_id', $distributor->id);
        } else {
            // Agency admin: every B2B agent's bookings (website bookings live
            // in the main Bookings screen).
            $query->whereNotNull('meta->agent_id');
            if ($reqAgentId = $request->query('agent_id')) {
                $query->where('meta->agent_id', (int) $reqAgentId);
            }
            if ($reqDistId = $request->query('distributor_id')) {
                $query->where('meta->distributor_id', (int) $reqDistId);
            }
        }

        if ($status = $request->query('status')) {
            $query->where('booking_status', strtoupper($status));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('booking_reference', 'like', "%{$search}%")
                  ->orWhere('pnr', 'like', "%{$search}%")
                  ->orWhere('contact_name', 'like', "%{$search}%")
                  ->orWhere('contact_email', 'like', "%{$search}%");
            });
        }

        $perPage = $request->integer('per_page', 15);
        $paginator = $query->paginate($perPage);

        $sanitized = collect($paginator->items())->map(function ($b) use ($role) {
            $firstTicket = $b->tickets->first();
            $meta = $b->meta ?? [];

            $item = [
                'id'                => $b->id,
                'booking_reference' => $b->booking_reference,
                'pnr'               => $b->pnr,
                'booking_status'    => $b->booking_status->value,
                'status_label'      => $b->booking_status->label(),
                'payment_status'    => $b->payment_status->value,
                'payment_label'     => $b->payment_status->label(),
                'ticket_status'     => $b->ticket_status?->value ?? ($firstTicket ? 'ISSUED' : 'PENDING'),
                'total_amount'      => (float) $b->total_amount,
                'currency'          => $b->currency ?: 'INR',
                'contact_name'      => $b->contact_name,
                'contact_email'     => $b->contact_email,
                'contact_phone'     => $b->contact_phone,
                'pax_count'         => $b->passengers->count() ?: ($b->pax_count_adult + $b->pax_count_child),
                'itinerary'         => $b->itinerary,
                'confirmed_at'      => $b->confirmed_at?->toIso8601String(),
                'cancelled_at'      => $b->cancelled_at?->toIso8601String(),
                'cancellation_fee'  => (float) $b->cancellation_fee,
                'refunded_amount'   => (float) $b->refunded_amount,
                'created_at'        => $b->created_at?->toIso8601String(),
                'ticket_download_url' => $b->isTicketed() ? "/api/v1/flight/bookings/{$b->booking_reference}/eticket/download" : null,
                'passengers'        => $b->passengers->map(fn ($p) => [
                    'id'              => $p->id,
                    'full_name'       => $p->full_name,
                    'passenger_type'  => $p->passenger_type->value,
                    'seat_number'     => $p->seat_preference ?? 'Standard',
                ]),
            ];

            // Role-governed financial visibility:
            if ($role === 'AGENT') {
                $item['commission_info'] = [
                    'agent_commission' => (float) ($meta['agent_commission'] ?? 0),
                ];
            } elseif ($role === 'DISTRIBUTOR') {
                $item['commission_info'] = [
                    'distributor_commission' => (float) ($meta['distributor_commission'] ?? 0),
                    'agent_commission'       => (float) ($meta['agent_commission'] ?? 0),
                    'agent_name'             => $meta['agent_name'] ?? null,
                ];
            } else {
                $item['commission_info'] = [
                    'distributor_commission' => (float) ($meta['distributor_commission'] ?? 0),
                    'agent_commission'       => (float) ($meta['agent_commission'] ?? 0),
                    'agent_name'             => $meta['agent_name'] ?? null,
                    'distributor_name'       => $meta['distributor_name'] ?? null,
                ];
            }

            return $item;
        });

        return ApiResponse::success([
            'data'         => $sanitized,
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ]);
    }

    /**
     * B2B Booking Creation with automatic agent/distributor tagging & commission calculation.
     * POST /api/v1/flight/b2b/bookings
     */
    public function b2bCreateBooking(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant($request);
        if (! $tenant) {
            return ApiResponse::errors(null, 'Tenant context required.', 400);
        }

        $actor = $this->resolveB2BActor($request, $tenant);
        $role = $actor['role'];
        if ($role === 'UNAUTHORIZED') {
            return ApiResponse::errors(null, 'User is not authorized to access B2B workspace.', 403);
        }
        $agent = $actor['agent'];
        $distributor = $actor['distributor'];
        $user = $actor['user'];

        // Enforce account status permissions
        if ($role === 'AGENT' && ! $agent->isOperational()) {
            return ApiResponse::errors(null, 'This B2B agent account is suspended. Booking creation forbidden.', 422);
        }
        if ($role === 'DISTRIBUTOR' && ! $distributor->isActive()) {
            return ApiResponse::errors(null, 'Distributor account is suspended. Booking creation forbidden.', 422);
        }

        $validated = $request->validate([
            'quote_id'                             => 'required_without:quote_reference|string',
            'quote_reference'                      => 'nullable|string',
            'flight_inventory_balance_id'          => 'nullable|integer',
            'passengers'                           => 'required|array|min:1',
            'passengers.*.first_name'              => 'required|string|max:100',
            'passengers.*.last_name'               => 'required|string|max:100',
            'passengers.*.title'                   => 'nullable|string|max:20',
            'passengers.*.passenger_type'          => 'nullable|string',
            'passengers.*.date_of_birth'           => 'nullable|date',
            'passengers.*.gender'                  => 'nullable|string',
            // Kept so international bookings can pass the passport check.
            'passengers.*.nationality'             => 'nullable|string|max:3',
            'passengers.*.passport_number'         => 'nullable|string|max:30',
            'passengers.*.passport_expiry'         => 'nullable|date',
            'passengers.*.passport_country'        => 'nullable|string|max:3',
            'contact_name'                         => 'nullable|string|max:150',
            'contact_email'                        => 'nullable|email|max:150',
            'contact_phone'                        => 'nullable|string|max:50',
            'idempotency_key'                      => 'nullable|string|max:100',
            'meta'                                 => 'nullable|array',
        ]);

        // Inventory permission check if balance ID provided
        if (! empty($validated['flight_inventory_balance_id'])) {
            $balance = FlightInventoryBalance::withoutGlobalTenant()->find($validated['flight_inventory_balance_id']);
            if ($balance) {
                if ($balance->tenant_id !== $tenant->id) {
                    return ApiResponse::errors(null, 'Cross-tenant inventory access forbidden.', 403);
                }
                if ($role === 'AGENT' && ($balance->holder_type !== HolderType::AGENT || (int) $balance->holder_id !== (int) $agent->id)) {
                    return ApiResponse::errors(null, 'Agent can only book inventory allocated to their agency.', 403);
                }
                if ($role === 'DISTRIBUTOR' && ($balance->holder_type !== HolderType::DISTRIBUTOR || (int) $balance->holder_id !== (int) $distributor->id)) {
                    return ApiResponse::errors(null, 'Distributor can only book inventory allocated to their distribution channel.', 403);
                }
                if ($balance->available < count($validated['passengers'])) {
                    return ApiResponse::errors(null, 'Insufficient seats in selected inventory allocation.', 422);
                }
            }
        }

        // Attach B2B Metadata and Commission info. Who booked and what they
        // earn are set here only: a client-sent agent_id / agent_commission
        // would otherwise be paid into the wallet at pay-wallet time.
        $meta = collect($validated['meta'] ?? [])->except([
            'agent_id', 'agent_name', 'agent_commission', 'agent_commission_rate',
            'distributor_id', 'distributor_name', 'distributor_commission', 'distributor_commission_rate',
            'b2b_channel',
        ])->all();
        if ($role === 'AGENT') {
            $meta['agent_id'] = $agent->id;
            $meta['agent_name'] = $agent->name;
            $meta['distributor_id'] = $agent->flight_distributor_id;
            $meta['distributor_name'] = $agent->distributor?->name;
            $meta['b2b_channel'] = 'AGENT';
            $meta['agent_commission_rate'] = (float) $agent->commission_rate;
        } elseif ($role === 'DISTRIBUTOR') {
            $meta['distributor_id'] = $distributor->id;
            $meta['distributor_name'] = $distributor->name;
            $meta['b2b_channel'] = 'DISTRIBUTOR';
            $meta['distributor_commission_rate'] = (float) $distributor->commission_rate;
        } else {
            $meta['b2b_channel'] = 'TENANT_DIRECT';
        }

        // Quote lookup for authoritative pricing and commission calculation
        $quoteId = $validated['quote_id'] ?? $validated['quote_reference'] ?? null;
        $quote = FlightFareQuote::where('quote_id', $quoteId)->orWhere('id', is_numeric($quoteId) ? (int)$quoteId : 0)->first();
        if ($quote) {
            $totalAmount = (float) $quote->customer_price;
            // Each partner earns only its own configured rate. A direct agent
            // (no distributor) has no distributor commission, and the agency's
            // own bookings earn none.
            $agentCommRate = (float) ($agent?->commission_rate ?? 0.0);
            $distCommRate = (float) ($distributor?->commission_rate ?? 0.0);

            $meta['agent_commission'] = round($totalAmount * ($agentCommRate / 100), 2);
            $meta['distributor_commission'] = round($totalAmount * ($distCommRate / 100), 2);

            // International is decided from the flight's airports, not the
            // client, so passport details can't be skipped by leaving it off.
            // Airports are read without the per-agency catalog filter: the ids
            // come from the flight itself, and a starter-catalog airport would
            // otherwise look missing to an agent.
            $inventory = $quote->flight_inventory_id
                ? \Modules\Flight\Models\FlightInventory::find($quote->flight_inventory_id)
                : null;
            $countries = $inventory
                ? \Modules\Flight\Models\FlightAirport::withoutGlobalScopes()
                    ->whereIn('id', [$inventory->origin_airport_id, $inventory->destination_airport_id])
                    ->pluck('country_code', 'id')
                : collect();
            $fromCountry = $inventory ? $countries->get($inventory->origin_airport_id) : null;
            $toCountry = $inventory ? $countries->get($inventory->destination_airport_id) : null;
            $validated['is_international'] = $fromCountry && $toCountry && strtoupper($fromCountry) !== strtoupper($toCountry);
        }

        $validated['meta'] = $meta;

        try {
            $booking = $this->bookingService->createBooking($validated, $tenant, $user);

            return ApiResponse::created([
                'booking_reference' => $booking->booking_reference,
                'id'                => $booking->id,
                'booking_status'    => $booking->booking_status->value,
                'payment_status'    => $booking->payment_status->value,
                'total_amount'      => (float) $booking->total_amount,
                'currency'          => $booking->currency,
                'contact_name'      => $booking->contact_name,
                'pax_count'         => $booking->passengers->count(),
                'commission_info'   => [
                    'agent_commission'       => $meta['agent_commission'] ?? 0,
                    'distributor_commission' => ($role !== 'AGENT') ? ($meta['distributor_commission'] ?? 0) : null,
                ],
                'created_at'        => $booking->created_at?->toIso8601String(),
            ]);
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 500);
        }
    }

    /**
     * B2B Wallet Deduction Payment with strict wallet ownership validation.
     * POST /api/v1/flight/b2b/bookings/{identifier}/pay-wallet
     */
    public function b2bPayWallet(Request $request, string $identifier): JsonResponse
    {
        $tenant = $this->resolveTenant($request);
        if (! $tenant) {
            return ApiResponse::errors(null, 'Tenant context required.', 400);
        }

        $actor = $this->resolveB2BActor($request, $tenant);
        $role = $actor['role'];
        if ($role === 'UNAUTHORIZED') {
            return ApiResponse::errors(null, 'User is not authorized to access B2B workspace.', 403);
        }
        $agent = $actor['agent'];
        $distributor = $actor['distributor'];
        $user = $actor['user'];

        // Enforce account status
        if ($role === 'AGENT' && ! $agent->isOperational()) {
            return ApiResponse::errors(null, 'Agent account is suspended. Wallet payment forbidden.', 422);
        }
        if ($role === 'DISTRIBUTOR' && ! $distributor->isActive()) {
            return ApiResponse::errors(null, 'Distributor account is suspended. Wallet payment forbidden.', 422);
        }

        // Resolve booking with tenant isolation
        $booking = FlightBooking::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($identifier) {
                $q->where('booking_reference', $identifier)
                  ->orWhere('id', is_numeric($identifier) ? (int) $identifier : 0);
            })
            ->first();

        if (! $booking) {
            return ApiResponse::errors(null, "Booking [{$identifier}] not found in this tenant.", 404);
        }

        if (! $this->actorOwnsBooking($actor, $booking)) {
            return ApiResponse::errors(null, 'You can only pay for bookings made by your own agency.', 403);
        }

        // Resolve target wallet and enforce wallet permissions
        $targetWalletId = $request->input('wallet_id');
        $wallet = null;

        if ($role === 'AGENT') {
            $wallet = $this->walletService->getOrCreateAgentWallet($agent);
            if ($targetWalletId && (int) $targetWalletId !== (int) $wallet->id) {
                return ApiResponse::errors(null, 'Agents are forbidden from debiting wallets other than their own assigned agency wallet.', 403);
            }
        } elseif ($role === 'DISTRIBUTOR') {
            $wallet = $this->walletService->getOrCreateDistributorWallet($distributor);
            if ($targetWalletId && (int) $targetWalletId !== (int) $wallet->id) {
                return ApiResponse::errors(null, 'Distributors are forbidden from debiting wallets other than their own distributor wallet.', 403);
            }
        } else {
            $wallet = $targetWalletId ? FlightB2BWallet::where('tenant_id', $tenant->id)->findOrFail((int) $targetWalletId) : $this->walletService->getOrCreateTenantWallet($tenant->id);
        }

        try {
            $payment = $this->paymentService->payWithWallet(
                $booking,
                ['wallet_id' => $wallet->id, 'idempotency_key' => $request->input('idempotency_key')],
                $tenant,
                $user
            );

            // Auto-credit commission if earned (only on newly created payment, not idempotent replay)
            $isNewPayment = (bool) ($payment->wasRecentlyCreated ?? false);
            $agentComm = (float) ($booking->meta['agent_commission'] ?? 0);
            $ownerTypeStr = is_object($wallet->owner_type) ? $wallet->owner_type->value : (string) $wallet->owner_type;
            if ($isNewPayment && $agentComm > 0 && $agent && strtoupper($ownerTypeStr) === 'AGENT') {
                $this->walletService->commissionCredit(
                    wallet: $wallet,
                    amount: $agentComm,
                    commissionReference: 'COMM-' . $booking->booking_reference,
                    actor: $user?->email ?? 'B2B-System'
                );
            }

            return ApiResponse::success([
                'payment'           => $payment,
                'booking_reference' => $booking->booking_reference,
                'booking_status'    => 'CONFIRMED',
                'payment_status'    => 'PAID',
                'wallet_balance'    => (float) $wallet->fresh()->balance,
                'available_credit'  => (float) $wallet->fresh()->available_credit,
                'message'           => 'Wallet payment completed and booking confirmed.',
            ]);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        }
    }

    /**
     * Role-permitted inventory balances.
     * GET /api/v1/flight/b2b/inventory
     */
    public function b2bInventory(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant($request);
        if (! $tenant) {
            return ApiResponse::errors(null, 'Tenant context required.', 400);
        }

        $actor = $this->resolveB2BActor($request, $tenant);
        $role = $actor['role'];
        if ($role === 'UNAUTHORIZED') {
            return ApiResponse::errors(null, 'User is not authorized to access B2B workspace.', 403);
        }
        $agent = $actor['agent'];
        $distributor = $actor['distributor'];

        $query = FlightInventoryBalance::withoutGlobalTenant()
            ->where('tenant_id', $tenant->id)
            ->with(['flightInventory.airline', 'flightInventory.originAirport', 'flightInventory.destinationAirport']);

        if ($role === 'AGENT') {
            $query->where('holder_type', HolderType::AGENT->value)
                ->where('holder_id', $agent->id);
        } elseif ($role === 'DISTRIBUTOR') {
            $query->where('holder_type', HolderType::DISTRIBUTOR->value)
                ->where('holder_id', $distributor->id);
        }

        $balances = $query->get();

        return ApiResponse::success($balances);
    }

    /**
     * Role-governed booking cancellation and refund servicing.
     * POST /api/v1/flight/b2b/bookings/{identifier}/cancel
     */
    public function b2bCancelBooking(Request $request, string $identifier): JsonResponse
    {
        $tenant = $this->resolveTenant($request);
        if (! $tenant) {
            return ApiResponse::errors(null, 'Tenant context required.', 400);
        }

        $actor = $this->resolveB2BActor($request, $tenant);
        $role = $actor['role'];
        if ($role === 'UNAUTHORIZED') {
            return ApiResponse::errors(null, 'User is not authorized to access B2B workspace.', 403);
        }
        $agent = $actor['agent'];
        $distributor = $actor['distributor'];

        $booking = FlightBooking::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($identifier) {
                $q->where('booking_reference', $identifier)
                  ->orWhere('id', is_numeric($identifier) ? (int) $identifier : 0);
            })
            ->first();

        if (! $booking) {
            return ApiResponse::errors(null, "Booking [{$identifier}] not found in this tenant.", 404);
        }

        if (! $this->actorOwnsBooking($actor, $booking)) {
            return ApiResponse::errors(null, 'You can only cancel bookings made by your own agency.', 403);
        }

        $reason = $request->input('reason', 'Cancelled via B2B Workspace');

        try {
            $result = $this->servicingService->cancelBooking($booking, $reason, $actor['user']?->email ?? 'B2B User');
            return ApiResponse::success($result);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        }
    }

    // ── TENANT ADMIN: DISTRIBUTORS & HIERARCHY ─────────────────────────────────

    /**
     * List all distributors for the current tenant.
     * GET /api/v1/flight/b2b/distributors
     */
    public function indexDistributors(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $query = FlightDistributor::query()
            ->with(['wallet', 'agents'])
            ->withCount('agents')
            ->latest('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper($status));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = $request->integer('per_page', 20);

        return ApiResponse::pagination($query->paginate($perPage));
    }

    /**
     * Create a new distributor.
     * POST /api/v1/flight/b2b/distributors
     */
    public function storeDistributor(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'code'             => 'nullable|string|max:50',
            'company_name'     => 'nullable|string|max:255',
            'email'            => 'required|email|max:255',
            'phone'            => 'nullable|string|max:30',
            'credit_limit'     => 'nullable|numeric|min:0',
            'commission_rate'  => 'nullable|numeric|min:0|max:100',
            'allow_sub_agents' => 'nullable|boolean',
            'max_agents'       => 'nullable|integer|min:1',
            'initial_balance'  => 'nullable|numeric|min:0',
            'notes'            => 'nullable|string|max:1000',
        ]);

        try {
            $distributor = $this->service->createDistributor($data);
            return ApiResponse::created($distributor);
        } catch (RuntimeException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        }
    }

    /**
     * Show a distributor's details, wallet, and agents.
     * GET /api/v1/flight/b2b/distributors/{id}
     */
    public function showDistributor(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $distributor = FlightDistributor::with(['wallet', 'agents.wallet', 'inventoryBalances'])
            ->findOrFail($id);

        return ApiResponse::success($distributor);
    }

    /**
     * Update a distributor.
     * PUT /api/v1/flight/b2b/distributors/{id}
     */
    public function updateDistributor(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $distributor = FlightDistributor::findOrFail($id);

        $data = $request->validate([
            'name'             => 'sometimes|required|string|max:255',
            'company_name'     => 'nullable|string|max:255',
            'email'            => 'sometimes|required|email|max:255',
            'phone'            => 'nullable|string|max:30',
            'credit_limit'     => 'nullable|numeric|min:0',
            'commission_rate'  => 'nullable|numeric|min:0|max:100',
            'allow_sub_agents' => 'nullable|boolean',
            'max_agents'       => 'nullable|integer|min:1',
            'notes'            => 'nullable|string|max:1000',
        ]);

        $distributor->update($data);

        if (isset($data['credit_limit'])) {
            $distributor->adjustCreditLimit((float) $data['credit_limit']);
        }

        return ApiResponse::success($distributor->fresh(['wallet']));
    }

    /**
     * Suspend a distributor.
     * POST /api/v1/flight/b2b/distributors/{id}/suspend
     */
    public function suspendDistributor(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $distributor = FlightDistributor::findOrFail($id);
        $reason = $request->input('reason', 'Suspended by admin');

        $distributor->suspend($reason);

        return ApiResponse::success([
            'message'     => "Distributor [{$distributor->name}] has been suspended.",
            'distributor' => $distributor->fresh(),
        ]);
    }

    /**
     * Activate a distributor.
     * POST /api/v1/flight/b2b/distributors/{id}/activate
     */
    public function activateDistributor(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $distributor = FlightDistributor::findOrFail($id);
        $distributor->activate();

        return ApiResponse::success([
            'message'     => "Distributor [{$distributor->name}] is now active.",
            'distributor' => $distributor->fresh(),
        ]);
    }

    /**
     * Adjust a distributor's credit limit.
     * POST /api/v1/flight/b2b/distributors/{id}/credit-limit
     */
    public function adjustCreditLimit(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $data = $request->validate([
            'credit_limit' => 'required|numeric|min:0',
        ]);

        $distributor = FlightDistributor::findOrFail($id);
        $distributor->adjustCreditLimit((float) $data['credit_limit']);

        return ApiResponse::success($distributor->fresh(['wallet']));
    }

    /**
     * Hierarchy Visualization Tree: Tenant -> Distributors -> Agents.
     * GET /api/v1/flight/b2b/hierarchy
     */
    public function hierarchy(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $tree = $this->service->getHierarchyTree();
        return ApiResponse::success($tree);
    }

    // ── DISTRIBUTOR: AGENTS & INVENTORY ───────────────────────────────────────

    /**
     * List agents belonging to a distributor.
     * GET /api/v1/flight/b2b/distributor/{distributorId}/agents
     */
    public function indexAgents(Request $request, int $distributorId): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $distributor = FlightDistributor::findOrFail($distributorId);

        $query = $distributor->agents()->with('wallet');

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper($status));
        }

        return ApiResponse::pagination($query->paginate($request->integer('per_page', 20)));
    }

    /**
     * Create an agent under a distributor.
     * POST /api/v1/flight/b2b/distributor/{distributorId}/agents
     */
    public function storeAgent(Request $request, int $distributorId): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $distributor = FlightDistributor::findOrFail($distributorId);
        $data = $this->validateAgent($request, $distributor->tenant_id);

        try {
            $agent = $this->service->createAgent($distributor, $data);
            return ApiResponse::created($agent);
        } catch (RuntimeException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        }
    }

    /**
     * Suspend an agent.
     * POST /api/v1/flight/b2b/agents/{id}/suspend
     */
    public function suspendAgent(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $agent = FlightAgent::findOrFail($id);
        $agent->suspend($request->input('reason', 'Suspended'));

        return ApiResponse::success([
            'message' => "Agent [{$agent->name}] has been suspended.",
            'agent'   => $agent->fresh(),
        ]);
    }

    /**
     * Activate an agent.
     * POST /api/v1/flight/b2b/agents/{id}/activate
     */
    public function activateAgent(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $agent = FlightAgent::findOrFail($id);
        $agent->activate();

        return ApiResponse::success([
            'message' => "Agent [{$agent->name}] is now active.",
            'agent'   => $agent->fresh(),
        ]);
    }

    /**
     * View wallet of a distributor.
     * GET /api/v1/flight/b2b/distributor/{id}/wallet
     */
    public function distributorWallet(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $distributor = FlightDistributor::with('wallet')->findOrFail($id);
        return ApiResponse::success([
            'distributor_id'   => $distributor->id,
            'name'             => $distributor->name,
            'wallet'           => $distributor->wallet,
            'available_credit' => $distributor->wallet?->available_credit ?? 0.0,
        ]);
    }

    /**
     * View inventory balances assigned to a distributor.
     * GET /api/v1/flight/b2b/distributor/{id}/inventory
     */
    public function distributorInventory(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        // Same tenant only: the id alone must not reach another agency's seats.
        $distributor = FlightDistributor::findOrFail($id);
        $balances = FlightInventoryBalance::withoutGlobalTenant()
            ->where('tenant_id', $distributor->tenant_id)
            ->where('holder_type', HolderType::DISTRIBUTOR->value)
            ->where('holder_id', $distributor->id)
            ->with(['flightInventory.airline', 'flightInventory.originAirport', 'flightInventory.destinationAirport'])
            ->get();

        return ApiResponse::success($balances);
    }

    // ── AGENT WORKSPACE ───────────────────────────────────────────────────────

    /**
     * Agent profile & operational status check.
     * GET /api/v1/flight/b2b/agents/{id}/profile
     */
    public function agentProfile(Request $request, int $id): JsonResponse
    {
        $agent = FlightAgent::with(['distributor', 'wallet'])->findOrFail($id);
        if ($denied = $this->denyUnlessAdminOrSelf($request, $agent)) {
            return $denied;
        }

        return ApiResponse::success([
            'agent'          => $agent,
            'distributor'    => $agent->distributor,
            'is_operational' => $agent->isOperational(),
            'wallet'         => $agent->wallet,
            'available_credit' => $agent->wallet?->available_credit ?? 0.0,
        ]);
    }

    /**
     * View inventory balances assigned to an agent.
     * GET /api/v1/flight/b2b/agents/{id}/inventory
     */
    public function agentInventory(Request $request, int $id): JsonResponse
    {
        $agent = FlightAgent::findOrFail($id);
        if ($denied = $this->denyUnlessAdminOrSelf($request, $agent)) {
            return $denied;
        }

        $balances = FlightInventoryBalance::withoutGlobalTenant()
            ->where('tenant_id', $agent->tenant_id)
            ->where('holder_type', HolderType::AGENT->value)
            ->where('holder_id', $agent->id)
            ->with(['flightInventory.airline', 'flightInventory.originAirport', 'flightInventory.destinationAirport'])
            ->get();

        return ApiResponse::success($balances);
    }

    // ── B2B AGENTS DIRECTLY UNDER THE AGENCY (no distributor) ────────────────

    /**
     * The agency's B2B partners.
     * GET /api/v1/flight/b2b/agents?status=&search=&per_page=
     */
    public function indexTenantAgents(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $query = FlightAgent::query()->with('wallet')->latest('id');

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper((string) $status));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('agency_name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return ApiResponse::pagination($query->paginate($request->integer('per_page', 20)));
    }

    /**
     * Add a B2B partner agency directly under the agency.
     * POST /api/v1/flight/b2b/agents
     */
    public function storeTenantAgent(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $tenant = $this->resolveTenant($request);
        if (! $tenant) {
            return ApiResponse::errors(null, 'Tenant context required.', 400);
        }

        $data = $this->validateAgent($request, $tenant->id);

        try {
            // The login (when asked for) and the agent are saved together:
            // if the agent fails, the new user is rolled back too.
            $agent = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $data, $tenant) {
                if (! empty($data['login_password'])) {
                    $data['user_id'] = $this->createAgentLogin($request, $data, $tenant->id)->id;
                }

                return $this->service->createAgent(null, $data, $tenant->id);
            });

            return ApiResponse::created($agent);
        } catch (RuntimeException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        }
    }

    /**
     * Creates the Flight Agent user a B2B partner signs in with. Tenant admins
     * cannot assign the built-in Flight Agent role from the Users screen
     * (Role::list hides it), so the login is created here instead.
     */
    protected function createAgentLogin(Request $request, array $data, int $tenantId): \Modules\User\Models\User
    {
        $base = \Illuminate\Support\Str::slug(\Illuminate\Support\Str::before($data['email'], '@'), '_') ?: 'agent';
        $username = $base;
        while (\Modules\User\Models\User::query()->withoutGlobalScopes()->where('username', $username)->exists()) {
            $username = $base.'_'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4));
        }

        $user = \Modules\User\Models\User::query()->withoutGlobalScopes()->create([
            'name'      => $data['name'],
            'username'  => $username,
            'email'     => $data['email'],
            'phone'     => $data['phone'] ?? null,
            'password'  => $data['login_password'],
            'tenant_id' => $tenantId,
            'branch_id' => $request->user()?->branch_id,
            'can_login' => true,
            'is_active' => true,
        ]);
        $user->syncRoles([DefaultRole::FlightAgent->value]);

        return $user;
    }

    /**
     * Edit a B2B partner's details.
     * PUT /api/v1/flight/b2b/agents/{id}
     */
    public function updateTenantAgent(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $agent = FlightAgent::findOrFail($id);
        $data = $this->validateAgent($request, $agent->tenant_id, $agent);

        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $agent, $data) {
            if (! empty($data['login_password'])) {
                $data['user_id'] = $this->createAgentLogin($request, [
                    'name'           => $data['name'] ?? $agent->name,
                    'email'          => $data['email'] ?? $agent->email,
                    'phone'          => $data['phone'] ?? $agent->phone,
                    'login_password' => $data['login_password'],
                ], $agent->tenant_id)->id;
            }

            $agent->update(collect($data)->only([
                'name', 'agency_name', 'iata_number', 'email', 'phone', 'commission_rate', 'notes', 'user_id',
            ])->all());
        });

        return ApiResponse::success($agent->fresh(['wallet']));
    }

    /**
     * Set how much a B2B partner may book on credit.
     * POST /api/v1/flight/b2b/agents/{id}/credit-limit
     */
    public function adjustAgentCreditLimit(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denyUnlessTenantAdmin($request)) {
            return $denied;
        }

        $data = $request->validate(['credit_limit' => 'required|numeric|min:0']);
        $agent = FlightAgent::findOrFail($id);
        $wallet = $this->walletService->getOrCreateAgentWallet($agent);

        try {
            $this->walletService->adjustCreditLimit(
                $wallet,
                (float) $data['credit_limit'],
                $request->user()?->email ?? 'admin',
                'CREDIT-LIMIT-'.$agent->code,
            );
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        }
        $agent->update(['credit_limit' => (float) $data['credit_limit']]);

        return ApiResponse::success($agent->fresh(['wallet']));
    }

    /** Agency admins, or the agent looking at its own record. */
    protected function denyUnlessAdminOrSelf(Request $request, FlightAgent $agent): ?JsonResponse
    {
        $user = $request->user();
        if ($this->isTenantAdmin($user) || ($user && (int) $agent->user_id === (int) $user->id)) {
            return null;
        }

        return ApiResponse::errors(null, 'You can only view your own B2B account.', 403);
    }

    /**
     * Shared rules for creating / editing a B2B agent. `user_id` links the
     * partner's login: it must be a user of the same agency, and not already
     * linked to another agent.
     */
    protected function validateAgent(Request $request, int $tenantId, ?FlightAgent $existing = null): array
    {
        $required = $existing ? 'sometimes|required' : 'required';
        $creatingLogin = filled($request->input('login_password'));

        $emailRules = [...explode('|', "{$required}|email|max:255")];
        if ($creatingLogin) {
            // The new login signs in with this email, so no user may have it yet.
            $emailRules[] = \Illuminate\Validation\Rule::unique('users', 'email');
        }

        return $request->validate([
            'name'            => "{$required}|string|max:255",
            'code'            => 'nullable|string|max:50',
            'agency_name'     => 'nullable|string|max:255',
            'iata_number'     => 'nullable|string|max:30',
            'email'           => $emailRules,
            // Creates a new Flight Agent login for this partner (instead of user_id).
            'login_password'  => 'nullable|string|min:8|max:255|prohibits:user_id',
            'phone'           => 'nullable|string|max:30',
            'credit_limit'    => 'nullable|numeric|min:0',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'initial_balance' => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string|max:1000',
            'user_id'         => [
                'nullable', 'integer',
                \Illuminate\Validation\Rule::exists('users', 'id')->where('tenant_id', $tenantId),
                \Illuminate\Validation\Rule::unique('flight_agents', 'user_id')
                    ->whereNull('deleted_at')
                    ->ignore($existing?->id),
            ],
        ], [
            'email.unique' => 'A user with this email already exists. Choose "Use existing user" to link that login instead.',
        ]);
    }
}

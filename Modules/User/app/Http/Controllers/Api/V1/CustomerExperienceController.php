<?php

namespace Modules\User\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Http\Controllers\Controller;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Support\ApiResponse;
use Modules\Support\Money;
use Modules\User\Http\Concerns\ResolvesAppCustomer;
use Modules\Voucher\Models\Voucher;

class CustomerExperienceController extends Controller
{
    use ResolvesAppCustomer;

    public function wallet(Request $request): JsonResponse
    {
        $customer = $this->customerForRequest($request);
        $currency = setting('default_currency') ?: config('app.currency', 'INR');
        $accountOwner = [
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
            'currency' => $currency,
        ];
        DB::table('customer_wallet_accounts')->insertOrIgnore($accountOwner + ['balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $account = DB::table('customer_wallet_accounts')->where($accountOwner)->first();
        $activeProgramId = \Modules\Loyalty\Models\LoyaltyProgram::currentForTenant($customer->tenant_id)?->id;
        $loyalty = LoyaltyCustomer::query()->where('customer_id', $customer->id)
            ->when($activeProgramId, fn ($query) => $query->orderByRaw(
                'CASE WHEN loyalty_program_id = ? THEN 0 ELSE 1 END', [$activeProgramId]))
            ->latest('id')->first();
        $moneyPage = DB::table('customer_wallet_transactions')
            ->where('tenant_id', $customer->tenant_id)->where('customer_id', $customer->id)
            ->latest('id')->paginate(min(50, max(1, $request->integer('per_page', 20))));
        $points = $loyalty
            ? DB::table('loyalty_transactions')->where('loyalty_customer_id', $loyalty->id)->latest('id')->limit(50)->get()
            : collect();

        return ApiResponse::success([
            'money' => ['balance' => Money::inDefaultCurrency($account->balance), 'currency' => $currency, 'transactions' => $moneyPage],
            'loyalty' => ['balance' => (int) ($loyalty?->points_balance ?? 0), 'lifetime_points' => (int) ($loyalty?->lifetime_points ?? 0), 'transactions' => $points],
        ]);
    }

    public function offers(Request $request): JsonResponse
    {
        $customer = $this->customerForRequest($request);
        $subtotal = max(0, $request->float('subtotal', 0));
        $offers = Voucher::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $customer->branch_id))
            ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->get()->filter(function (Voucher $offer) use ($customer, $subtotal) {
                if (filled(data_get($offer->meta, 'customer_id')) && (int) data_get($offer->meta, 'customer_id') !== (int) $customer->id) return false;
                if ($offer->usageLimitReached($customer->id) || $offer->perCustomerUsageLimitReached($customer->id)) return false;
                $days = collect(data_get($offer->conditions, 'available_days', []))->map(fn ($day) => strtolower((string) $day));
                if ($days->isNotEmpty() && ! $days->contains(strtolower(today()->englishDayOfWeek))) return false;
                if ($subtotal <= 0) return true;
                if ($offer->minimum_spend && $subtotal < $offer->minimum_spend->amount()) return false;
                if ($offer->maximum_spend && $subtotal > $offer->maximum_spend->amount()) return false;
                return true;
            })->map(fn (Voucher $offer) => [
                'id' => $offer->id, 'code' => $offer->code, 'name' => $offer->name, 'description' => $offer->description,
                'value_text' => $offer->getValueText(), 'minimum_spend' => $offer->minimum_spend?->amount(),
                'maximum_spend' => $offer->maximum_spend?->amount(), 'ends_at' => optional($offer->end_date)->toDateString(),
                'usage_limit' => $offer->usage_limit, 'per_customer_limit' => $offer->per_customer_limit,
                'eligible' => true,
            ])->values();

        return ApiResponse::success(['offers' => $offers]);
    }

    public function referral(Request $request): JsonResponse
    {
        $customer = $this->customerForRequest($request);
        $referral = DB::table('customer_referral_codes')
            ->where('tenant_id', $customer->tenant_id)->where('referrer_customer_id', $customer->id)->first();
        if (! $referral) {
            for ($attempt = 0; $attempt < 5 && ! $referral; $attempt++) {
                $code = strtoupper(Str::random(8));
                try {
                    DB::table('customer_referral_codes')->insert([
                        'tenant_id' => $customer->tenant_id, 'referrer_customer_id' => $customer->id,
                        'code' => $code, 'is_active' => true, 'reward_points' => (int) setting('customer_referral_reward_points', 100),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    // Another request may have created the owner's code, or
                    // this random code collided. Resolve by owner and retry.
                }
                $referral = DB::table('customer_referral_codes')->where('tenant_id', $customer->tenant_id)
                    ->where('referrer_customer_id', $customer->id)->first();
            }
            abort_unless($referral, 503, 'A referral code could not be created. Please retry.');
        }
        $history = DB::table('customer_referrals')->where('tenant_id', $customer->tenant_id)
            ->where('referrer_customer_id', $customer->id)->whereNotNull('referred_customer_id')->latest('id')->limit(50)->get();

        return ApiResponse::success(['code' => $referral->code, 'reward_points' => $referral->reward_points, 'history' => $history]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $customer = $this->customerForRequest($request);
        $query = DB::table('customer_notifications')->where('tenant_id', $customer->tenant_id)->where('customer_id', $customer->id);
        $unread = (clone $query)->whereNull('read_at')->count();
        $notifications = $query->latest('id')->paginate(min(50, max(1, $request->integer('per_page', 20))));
        $notifications->setCollection($notifications->getCollection()->map(fn ($item) => [
            'id' => $item->reference,
            'type' => $item->type,
            'title' => $item->title,
            'message' => $item->message,
            'action_url' => $item->action_url,
            'data' => is_string($item->data) ? (json_decode($item->data, true) ?: []) : ($item->data ?? []),
            'read' => $item->read_at !== null,
            'created_at' => $item->created_at,
        ]));

        return ApiResponse::success(['unread_count' => $unread, 'notifications' => $notifications]);
    }

    public function readNotification(Request $request, string $reference): JsonResponse
    {
        $customer = $this->customerForRequest($request);
        DB::table('customer_notifications')->where('tenant_id', $customer->tenant_id)->where('customer_id', $customer->id)
            ->where('reference', $reference)->update(['read_at' => now(), 'updated_at' => now()]);
        return ApiResponse::success(message: 'Notification marked as read.');
    }

    public function readAllNotifications(Request $request): JsonResponse
    {
        $customer = $this->customerForRequest($request);
        DB::table('customer_notifications')->where('tenant_id', $customer->tenant_id)->where('customer_id', $customer->id)
            ->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
        return ApiResponse::success(message: 'All notifications marked as read.');
    }

    public function support(Request $request): JsonResponse
    {
        $this->customerForRequest($request);
        return ApiResponse::success([
            'support' => ['phone' => setting('support_phone'), 'email' => setting('support_email'), 'whatsapp' => setting('support_whatsapp'), 'hours' => setting('support_hours')],
            'about' => ['title' => setting('restaurant_name'), 'description' => setting('customer_app_about'), 'terms_url' => setting('terms_url'), 'privacy_url' => setting('privacy_url')],
        ]);
    }
}

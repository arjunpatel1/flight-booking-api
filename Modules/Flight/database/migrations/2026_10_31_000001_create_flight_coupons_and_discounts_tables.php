<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Flight coupons (entered by code) and flight discounts (offered automatically)
 * get their own tables, separate from the restaurant `vouchers` table, and every
 * row belongs to exactly one tenant so each agency sees only its own offers.
 *
 * Existing flight vouchers (conditions.channels contains "flights") are moved
 * across with the same ids, so bookings that already reference an offer id
 * keep pointing at the same offer. The original voucher rows are soft-deleted.
 */
return new class extends Migration
{
    private const TABLES = ['flight_coupons', 'flight_discounts'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->json('name');
                $table->json('description')->nullable();
                $table->string('code', 200)->nullable();
                $table->string('type', 16); // percent | fixed
                $table->decimal('value', 18, 4);
                $table->decimal('max_discount', 18, 4)->nullable();
                $table->decimal('minimum_spend', 18, 4)->nullable();
                $table->decimal('maximum_spend', 18, 4)->nullable();
                $table->json('conditions')->nullable(); // routes, airlines, flight_numbers, fare_families
                $table->unsignedInteger('usage_limit')->nullable();
                $table->unsignedInteger('per_customer_limit')->nullable();
                $table->unsignedInteger('used')->default(0);
                $table->boolean('is_active')->default(true);
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                // Codes are unique per tenant; enforced in validation so a
                // soft-deleted code can be reused.
                $table->index(['tenant_id', 'code']);
            });
        }

        Schema::table('flight_offer_redemptions', function (Blueprint $table): void {
            $table->unsignedBigInteger('voucher_id')->nullable()->change();
            $table->string('offer_type', 16)->nullable()->after('voucher_id'); // coupon | discount
            $table->unsignedBigInteger('offer_id')->nullable()->after('offer_type');
            $table->index(['offer_type', 'offer_id']);
            // One redemption of a given offer per booking.
            $table->unique(['offer_type', 'offer_id', 'booking_id'], 'flight_offer_redemptions_offer_booking_unique');
        });

        $this->moveExistingFlightVouchers();
    }

    public function down(): void
    {
        // Bring the moved voucher rows back before dropping the new tables.
        if (Schema::hasTable('vouchers')) {
            DB::table('vouchers')
                ->whereNotNull('deleted_at')
                ->where('meta', 'like', '%moved_to_flight_offers%')
                ->update(['deleted_at' => null]);
        }

        Schema::table('flight_offer_redemptions', function (Blueprint $table): void {
            $table->dropUnique('flight_offer_redemptions_offer_booking_unique');
            $table->dropIndex(['offer_type', 'offer_id']);
            $table->dropColumn(['offer_type', 'offer_id']);
        });

        foreach (self::TABLES as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }

    private function moveExistingFlightVouchers(): void
    {
        if (! Schema::hasTable('vouchers')) {
            return;
        }

        $flightTenants = Schema::hasTable('flight_routes')
            ? DB::table('flight_routes')->whereNotNull('tenant_id')->distinct()->pluck('tenant_id')
            : collect();

        $vouchers = DB::table('vouchers')
            ->whereNull('deleted_at')
            ->where('conditions', 'like', '%"flights"%')
            ->get();

        foreach ($vouchers as $voucher) {
            $meta = json_decode((string) $voucher->meta, true) ?: [];
            $conditions = json_decode((string) $voucher->conditions, true) ?: [];
            if (! in_array('flights', (array) ($conditions['channels'] ?? []), true)) {
                continue;
            }

            // The offer's tenant: set explicitly, else whoever created it,
            // else the only tenant that runs flights.
            $tenantId = $meta['tenant_id'] ?? null;
            if (! $tenantId && $voucher->created_by && Schema::hasTable('users')) {
                $tenantId = DB::table('users')->where('id', $voucher->created_by)->value('tenant_id');
            }
            if (! $tenantId && $flightTenants->count() === 1) {
                $tenantId = $flightTenants->first();
            }
            if (! $tenantId) {
                Log::warning('Flight voucher not moved: no tenant could be determined.', ['voucher_id' => $voucher->id]);
                continue;
            }

            $table = ! empty($meta['flight_auto_apply']) ? 'flight_discounts' : 'flight_coupons';
            unset($conditions['channels'], $conditions['available_days'], $conditions['categories'], $conditions['products'], $conditions['order_types']);

            DB::table($table)->insert([
                'id' => $voucher->id,
                'tenant_id' => (int) $tenantId,
                'name' => $voucher->name,
                'description' => $voucher->description,
                'code' => $voucher->code,
                'type' => $voucher->type,
                'value' => $voucher->value,
                'max_discount' => $voucher->max_discount,
                'minimum_spend' => $voucher->minimum_spend,
                'maximum_spend' => $voucher->maximum_spend,
                'conditions' => json_encode($conditions),
                'usage_limit' => $voucher->usage_limit,
                'per_customer_limit' => $voucher->per_customer_limit,
                'used' => (int) $voucher->used,
                'is_active' => (bool) $voucher->is_active,
                'start_date' => $voucher->start_date,
                'end_date' => $voucher->end_date,
                'created_by' => $voucher->created_by,
                'created_at' => $voucher->created_at,
                'updated_at' => now(),
            ]);

            $kind = $table === 'flight_discounts' ? 'discount' : 'coupon';
            DB::table('flight_offer_redemptions')->where('voucher_id', $voucher->id)
                ->update(['offer_type' => $kind, 'offer_id' => $voucher->id]);

            DB::table('vouchers')->where('id', $voucher->id)->update([
                'deleted_at' => now(),
                'meta' => json_encode($meta + ['moved_to_flight_offers' => $table]),
            ]);
        }
    }
};

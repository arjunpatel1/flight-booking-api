<?php

namespace Modules\Flight\Services\Customer;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightCustomer;
use Modules\Saas\Models\Tenant;
use Modules\User\Models\CustomerOtpChallenge;
use Modules\User\Services\CustomerOtp\CustomerOtpSender;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobile number + OTP login for flight customers (guest bookers included).
 *
 * Challenges reuse the customer OTP table and delivery providers of the User
 * module. A verified phone lets the customer see every booking whose contact
 * mobile matches, so the phone must be proven here before it is trusted.
 */
class FlightCustomerOtpService
{
    public const PURPOSE = 'flight_login';

    public function __construct(private readonly CustomerOtpSender $sender) {}

    public function request(Request $request, string $rawPhone, string $deviceId): array
    {
        $phone = $this->normalizePhone($rawPhone);
        $resendSeconds = max(30, (int) config('services.customer_otp.resend_seconds', 60));
        $ttl = max(2, min(15, (int) config('services.customer_otp.ttl_minutes', 5)));

        $rateKey = 'flight-customer-otp-send:'.hash('sha256', $phone.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            abort(Response::HTTP_TOO_MANY_REQUESTS, 'Please wait before requesting another code.');
        }

        $recent = CustomerOtpChallenge::query()
            ->where('phone', $phone)
            ->where('purpose', self::PURPOSE)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();
        if ($recent?->sent_at?->gt(now()->subSeconds($resendSeconds))) {
            return [
                'challenge_id' => $recent->reference,
                'expires_at' => $recent->expires_at->toIso8601String(),
                'resend_after_seconds' => (int) now()->diffInSeconds($recent->sent_at->addSeconds($resendSeconds)),
            ];
        }

        RateLimiter::hit($rateKey, 600);

        $latestBooking = $this->bookingsForPhone(FlightBooking::query(), $phone)->latest('id')->first();
        $tenantId = $latestBooking?->tenant_id ?: $this->fallbackTenantId();

        // An unknown number gets the same response shape as a real one, so the
        // endpoint cannot be used to discover who has booked with us.
        if (! $tenantId) {
            return [
                'challenge_id' => (string) Str::uuid(),
                'expires_at' => now()->addMinutes($ttl)->toIso8601String(),
                'resend_after_seconds' => $resendSeconds,
            ];
        }

        $reference = (string) Str::uuid();
        $testCode = $this->testCodeFor($phone);
        $otp = $testCode ?? (string) random_int(100000, 999999);
        $challenge = CustomerOtpChallenge::query()->create([
            'reference' => $reference,
            'tenant_id' => $tenantId,
            'phone' => $phone,
            'channel' => 'whatsapp',
            'purpose' => self::PURPOSE,
            'otp_hash' => $this->hashOtp($reference, $otp),
            'installation_hash' => hash('sha256', $deviceId),
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'max_attempts' => max(3, min(10, (int) config('services.customer_otp.max_attempts', 5))),
            'sent_at' => now(),
            'expires_at' => now()->addMinutes($ttl),
        ]);

        if ($testCode !== null) {
            $challenge->forceFill(['provider_reference' => 'local-test-otp'])->save();
        } else {
            $this->deliver($challenge, $phone, $latestBooking?->contact_email, $otp, $ttl, (int) $tenantId);
        }

        return [
            'challenge_id' => $reference,
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'resend_after_seconds' => $resendSeconds,
        ];
    }

    public function verify(Request $request, string $reference, string $otp, string $deviceId): FlightCustomer
    {
        $customer = DB::transaction(function () use ($request, $reference, $otp, $deviceId): ?FlightCustomer {
            $challenge = CustomerOtpChallenge::query()
                ->lockForUpdate()
                ->where('reference', $reference)
                ->where('purpose', self::PURPOSE)
                ->first();
            $validBoundary = $challenge
                && hash_equals($challenge->installation_hash, hash('sha256', $deviceId))
                && hash_equals($challenge->ip_hash, hash('sha256', (string) $request->ip()));
            if (! $validBoundary || $challenge->consumed_at || $challenge->expires_at->isPast() || $challenge->attempts >= $challenge->max_attempts) {
                throw ValidationException::withMessages(['otp' => ['The verification code is invalid or expired.']]);
            }

            $challenge->increment('attempts');
            if (! hash_equals($challenge->otp_hash, $this->hashOtp($reference, $otp))) {
                return null;
            }
            $challenge->forceFill(['consumed_at' => now()])->save();

            $phone = (string) $challenge->phone;
            $customer = FlightCustomer::query()
                ->whereIn('phone', array_unique([$phone, $this->lastTenDigits($phone)]))
                ->first();

            if (! $customer) {
                $latestBooking = $this->bookingsForPhone(FlightBooking::query(), $phone)->latest('id')->first();
                $customer = FlightCustomer::query()->create([
                    'name' => trim((string) $latestBooking?->contact_name) ?: 'Traveller',
                    // Email stays empty: a booking's contact email is unverified
                    // and must not claim that address for this account.
                    'email' => null,
                    'phone' => $phone,
                    'phone_verified_at' => now(),
                    'password' => Hash::make(Str::random(48)),
                    'frequent_flyer_number' => FlightCustomer::newFrequentFlyerNumber(),
                    'is_active' => true,
                ]);
            } elseif (! $customer->phone_verified_at) {
                $customer->forceFill(['phone_verified_at' => now()])->save();
            }

            return $customer;
        }, 3);

        if (! $customer) {
            throw ValidationException::withMessages(['otp' => ['The verification code is invalid or expired.']]);
        }
        abort_unless($customer->is_active, Response::HTTP_FORBIDDEN, 'This flight customer account is disabled.');

        return $customer;
    }

    /**
     * Restrict a booking query to the bookings this customer owns: linked to
     * the account, made with its email, or made with its OTP-verified mobile.
     */
    public function ownedBy(Builder $query, FlightCustomer $customer): Builder
    {
        return $query->where(function (Builder $owned) use ($customer): void {
            $owned->where('flight_customer_id', $customer->id);
            if (filled($customer->email)) {
                $owned->orWhereRaw('LOWER(contact_email) = ?', [Str::lower($customer->email)]);
            }
            if ($customer->phone_verified_at && filled($customer->phone)) {
                $owned->orWhere(fn (Builder $byPhone) => $this->bookingsForPhone($byPhone, (string) $customer->phone));
            }
        });
    }

    /**
     * Stricter than ownedBy(), for actions that move money (cancellation).
     * The account email is never verified, so anyone could register with
     * someone else's address; only the account link or an OTP-verified
     * mobile proves the booking is theirs.
     */
    public function manageableBy(Builder $query, FlightCustomer $customer): Builder
    {
        return $query->where(function (Builder $owned) use ($customer): void {
            $owned->where('flight_customer_id', $customer->id);
            if ($customer->phone_verified_at && filled($customer->phone)) {
                $owned->orWhere(fn (Builder $byPhone) => $this->bookingsForPhone($byPhone, (string) $customer->phone));
            }
        });
    }

    /** Contact phones are stored as typed ("+91 98765-43210"), so compare the last 10 digits. */
    private function bookingsForPhone(Builder $query, string $phone): Builder
    {
        $digits = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(contact_phone, ''), ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), '.', '')";

        return $query->whereRaw("SUBSTR({$digits}, -10) = ?", [$this->lastTenDigits($phone)]);
    }

    private function deliver(CustomerOtpChallenge $challenge, string $phone, ?string $bookingEmail, #[\SensitiveParameter] string $otp, int $ttl, int $tenantId): void
    {
        if (app()->environment('local')) {
            // Developer machines usually have WhatsApp disabled and
            // MAIL_MAILER=log, so a "sent" code never reaches anyone. Always
            // leave one easy-to-find line in the local log.
            Log::info("Flight customer OTP for {$phone}: {$otp} (local environment only)");
        }

        $brand = (string) (Tenant::query()->withoutGlobalScopes()->whereKey($tenantId)->value('name') ?: config('app.name'));
        $attempts = [['whatsapp', $phone]];
        if (filled($bookingEmail) && filter_var($bookingEmail, FILTER_VALIDATE_EMAIL)) {
            // Fall back to the email used on the booking made with this number.
            $attempts[] = ['email', Str::lower(trim($bookingEmail))];
        }

        foreach ($attempts as [$channel, $recipient]) {
            try {
                $providerReference = $this->sender->send($channel, $recipient, $otp, $ttl, $tenantId, $brand);
                $challenge->forceFill(['channel' => $channel, 'provider_reference' => $providerReference])->save();

                return;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if (app()->environment('local')) {
            // No provider worked; the code was logged above.
            $challenge->forceFill(['provider_reference' => 'local-log'])->save();

            return;
        }

        $challenge->delete();
        abort(Response::HTTP_SERVICE_UNAVAILABLE, 'We could not send the verification code right now. Please try again shortly.');
    }

    private function fallbackTenantId(): ?int
    {
        $tenantId = (int) config('flight.customer_otp_tenant_id');

        return $tenantId > 0 && Tenant::query()->withoutGlobalScopes()->whereKey($tenantId)->exists() ? $tenantId : null;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 10) {
            $digits = '91'.$digits;
        }
        if (! preg_match('/^[1-9][0-9]{9,14}$/', $digits)) {
            throw ValidationException::withMessages(['phone' => ['Enter a valid mobile number.']]);
        }

        return $digits;
    }

    private function lastTenDigits(string $phone): string
    {
        return substr(preg_replace('/\D+/', '', $phone), -10);
    }

    private function hashOtp(string $reference, string $otp): string
    {
        return hash_hmac('sha256', $reference.'|'.$otp, (string) config('app.key'));
    }

    /** Same QA allow-list as the restaurant customer OTP; never active in production. */
    private function testCodeFor(string $phone): ?string
    {
        if (app()->environment('production')) {
            return null;
        }
        $code = trim((string) config('services.customer_otp.test_code'));
        $recipients = (array) config('services.customer_otp.test_recipients', []);

        return preg_match('/^\d{6}$/', $code) && in_array($phone, $recipients, true) ? $code : null;
    }
}

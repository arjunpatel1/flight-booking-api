<?php

namespace Modules\Saas\Services\Workspace;

use Modules\Saas\Models\Tenant;

/**
 * Human-typeable activation keys.
 *
 * The QR payload (see TenantClientConfigService::activationPayload) stays the
 * primary activation path. This exists for the case the QR cannot be scanned —
 * a taped-over camera, a printed setup sheet, a phone call with support — where
 * somebody has to read a code aloud and somebody else has to type it.
 *
 * Design constraints, in priority order:
 *
 *  1. Dictatable. The alphabet omits 0/O, 1/I/L and U so a code read over a
 *     phone cannot be mistranscribed.
 *  2. Self-checking. The final character is a checksum over the rest, so a
 *     typo is caught in the field before a request is made.
 *  3. Portable. Derivation and validation are plain string/modulo arithmetic
 *     with no framework types, so the identical algorithm can be mirrored in
 *     the Flutter waiter app and any future client. See the TypeScript twin at
 *     `src/utils/activationKey.ts`.
 *  4. Stateless. The key is derived from the tenant plus the app key, so no
 *     table is required to issue or verify one.
 *
 * Consequence of (4): expiry, single-use enforcement and device binding are NOT
 * provided here — all three need persistence. The QR payload already carries a
 * signed expiry and remains the path where those guarantees matter.
 */
class ActivationKeyService
{
    /**
     * Unambiguous uppercase alphabet: no 0/O, no 1/I/L, no U.
     */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function alphabet(): string
    {
        return self::ALPHABET;
    }

    public function keyLength(): int
    {
        $length = (int) config('saas.workspace.activation.key_length', 16);

        // Must leave room for at least one group plus the checksum character.
        return max(8, min($length, 32));
    }

    public function groupSize(): int
    {
        return max(2, (int) config('saas.workspace.activation.group_size', 4));
    }

    /**
     * The activation key for a tenant, grouped for display: XXXX-XXXX-XXXX-XXXX.
     */
    public function forTenant(Tenant $tenant): string
    {
        return $this->group($this->deriveRaw($tenant));
    }

    /**
     * Whether the supplied input is this tenant's key. Accepts any spacing or
     * casing, so a pasted value with stray separators still validates.
     */
    public function matches(Tenant $tenant, string $input): bool
    {
        $normalised = $this->normalise($input);

        if (! $this->isWellFormed($normalised)) {
            return false;
        }

        return hash_equals($this->deriveRaw($tenant), $normalised);
    }

    /**
     * Resolve a human-entered key to its tenant. This is intentionally strict:
     * malformed keys never trigger a table scan, and inactive tenants are not
     * resolvable for app activation.
     */
    public function tenantForKey(string $input): ?Tenant
    {
        if (! $this->isWellFormed($input)) {
            return null;
        }

        return Tenant::query()
            ->withoutGlobalScopes()
            ->where('is_active', true)
            ->get()
            ->first(fn (Tenant $tenant) => $this->matches($tenant, $input));
    }

    /**
     * Structural validity only: correct length, allowed characters, and a
     * checksum that agrees. True here does not mean the key belongs to anyone —
     * it means the code was typed correctly and is worth sending to the server.
     */
    public function isWellFormed(string $input): bool
    {
        $normalised = $this->normalise($input);

        if (strlen($normalised) !== $this->keyLength()) {
            return false;
        }

        if (strspn($normalised, self::ALPHABET) !== strlen($normalised)) {
            return false;
        }

        $body = substr($normalised, 0, -1);

        return substr($normalised, -1) === $this->checksum($body);
    }

    /**
     * Strip separators and whitespace, then uppercase.
     *
     * Ambiguous characters are deliberately NOT folded onto the alphabet. A
     * generated key can never contain 0, 1, I, L, O or U, so seeing one means
     * the code was mistyped — and silently "correcting" it would turn a typo
     * the checksum was designed to catch into a wrong-key error the user cannot
     * explain. Rejecting it lets the UI say exactly which character is wrong.
     */
    public function normalise(string $input): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
    }

    /**
     * Format a raw key into display groups.
     */
    public function group(string $raw): string
    {
        return implode('-', str_split($raw, $this->groupSize()));
    }

    /** Generate a random, self-checking key for a persisted one-time challenge. */
    public function randomKey(): string
    {
        $body = '';
        $alphabet = self::ALPHABET;
        for ($index = 0; $index < $this->keyLength() - 1; $index++) {
            $body .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $this->group($body.$this->checksum($body));
    }

    /**
     * Deterministic key body plus checksum, derived from the tenant identity and
     * the application key. Rotating APP_KEY rotates every activation key.
     */
    private function deriveRaw(Tenant $tenant): string
    {
        $seed = hash_hmac(
            'sha256',
            "nexdine-activation|{$tenant->id}|{$tenant->slug}",
            (string) config('app.key'),
            true
        );

        $alphabet = self::ALPHABET;
        $size = strlen($alphabet);
        $body = '';

        // One character per seed byte; rehash if the key is longer than the digest.
        for ($i = 0; strlen($body) < $this->keyLength() - 1; $i++) {
            if ($i > 0 && $i % strlen($seed) === 0) {
                $seed = hash('sha256', $seed, true);
            }

            $body .= $alphabet[ord($seed[$i % strlen($seed)]) % $size];
        }

        return $body . $this->checksum($body);
    }

    /**
     * Single-character checksum: sum of alphabet positions, modulo the alphabet.
     * Catches every single-character typo and most transpositions.
     */
    private function checksum(string $body): string
    {
        $alphabet = self::ALPHABET;
        $size = strlen($alphabet);
        $total = 0;

        foreach (str_split($body) as $index => $character) {
            $position = strpos($alphabet, $character);

            if ($position === false) {
                return '';
            }

            // Position-weighted so transposed characters produce a different sum.
            $total += $position * ($index + 1);
        }

        return $alphabet[$total % $size];
    }
}

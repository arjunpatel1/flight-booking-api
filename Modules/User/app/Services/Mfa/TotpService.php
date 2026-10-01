<?php

namespace Modules\User\Services\Mfa;

class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $length = 32): string
    {
        $secret = '';
        $bytes = random_bytes($length);

        for ($index = 0; $index < $length; $index++) {
            $secret .= self::ALPHABET[ord($bytes[$index]) % strlen(self::ALPHABET)];
        }

        return $secret;
    }

    public function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code);
        $counter = (int) floor(time() / 30);

        if (strlen($code) !== 6) {
            return false;
        }

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->code($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function provisioningUri(string $secret, string $email, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($email),
            $secret,
            rawurlencode($issuer),
        );
    }

    private function code(string $secret, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $counter), $this->decodeBase32($secret), true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function decodeBase32(string $secret): string
    {
        $bits = '';

        foreach (str_split(strtoupper($secret)) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position !== false) {
                $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
            }
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $decoded;
    }
}

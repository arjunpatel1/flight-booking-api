<?php

namespace Modules\Aggregator\Services\PartnerApi;

final class PartnerSignature
{
    public function canonical(string $method, string $path, string $timestamp, string $nonce, string $body, int $version = 2): string
    {
        return implode("\n", [
            strtoupper($method),
            $version >= 2 ? $this->canonicalTarget($path) : '/'.ltrim(explode('?', $path, 2)[0], '/'),
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    public function canonicalTarget(string $target): string
    {
        [$path, $query] = array_pad(explode('?', $target, 2), 2, '');
        $path = '/'.ltrim($path, '/');
        if ($query === '') return $path;

        $pairs = collect(explode('&', $query))->filter(fn (string $part) => $part !== '')
            ->map(function (string $part): array {
                [$key, $value] = array_pad(explode('=', $part, 2), 2, '');

                return [rawurldecode(str_replace('+', ' ', $key)), rawurldecode(str_replace('+', ' ', $value))];
            })->sort(fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]])->values();
        $canonicalQuery = $pairs->map(fn (array $pair) => rawurlencode($pair[0]).'='.rawurlencode($pair[1]))->implode('&');

        return $canonicalQuery === '' ? $path : $path.'?'.$canonicalQuery;
    }

    public function sign(string $secret, string $method, string $path, string $timestamp, string $nonce, string $body, int $version = 2): string
    {
        return hash_hmac('sha256', $this->canonical($method, $path, $timestamp, $nonce, $body, $version), $secret);
    }

    public function verify(string $signature, string $secret, string $method, string $path, string $timestamp, string $nonce, string $body, int $version = 2): bool
    {
        return hash_equals($this->sign($secret, $method, $path, $timestamp, $nonce, $body, $version), strtolower($signature));
    }
}

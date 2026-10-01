<?php

namespace Modules\Printer\Services\AgentSignature;

class AgentSignatureService implements AgentSignatureServiceInterface
{
    /** @inheritDoc */
    public function verifySignature(string $agentId, string $secret, array $payload, string $providedSignature): bool
    {
        $expectedSignature = $this->generateSignature($agentId, $secret, $payload);
        return hash_equals($expectedSignature, $providedSignature);
    }

    public function verifyRawSignature(string $agentId, string $secret, string $body, string $providedSignature): bool
    {
        $expectedSignature = $this->generateRawSignature($agentId, $secret, $body);
        return hash_equals($expectedSignature, $providedSignature);
    }

    /** @inheritDoc */
    public function generateSignature(string $agentId, string $secret, array $payload): string
    {
        $message = $agentId . ':' . json_encode($payload);
        return hash_hmac('sha256', $message, $secret);
    }

    public function generateRawSignature(string $agentId, string $secret, string $body): string
    {
        return hash_hmac('sha256', $agentId . ':' . $body, $secret);
    }

    public function generateFreshSignature(
        string $agentId,
        string $secret,
        string $body,
        string $timestamp,
        string $nonce,
    ): string {
        return hash_hmac(
            'sha256',
            implode(':', [$agentId, $timestamp, $nonce, $body]),
            $secret,
        );
    }

    public function verifyFreshSignature(
        string $agentId,
        string $secret,
        string $body,
        string $timestamp,
        string $nonce,
        string $providedSignature,
    ): bool {
        return hash_equals(
            $this->generateFreshSignature($agentId, $secret, $body, $timestamp, $nonce),
            $providedSignature,
        );
    }
}

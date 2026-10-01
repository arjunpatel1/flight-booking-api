<?php

namespace Modules\Printer\Services\AgentSignature;

interface AgentSignatureServiceInterface
{
    /**
     * Generate signature
     *
     * @param string $agentId
     * @param string $secret
     * @param array $payload
     * @return string
     */
    public function generateSignature(string $agentId, string $secret, array $payload): string;

    public function generateRawSignature(string $agentId, string $secret, string $body): string;

    public function generateFreshSignature(
        string $agentId,
        string $secret,
        string $body,
        string $timestamp,
        string $nonce,
    ): string;

    public function verifyFreshSignature(
        string $agentId,
        string $secret,
        string $body,
        string $timestamp,
        string $nonce,
        string $providedSignature,
    ): bool;

    /**
     * Verify signature
     * @param string $agentId
     * @param string $secret
     * @param array $payload
     * @param string $providedSignature
     * @return bool
     */
    public function verifySignature(string $agentId, string $secret, array $payload, string $providedSignature): bool;

    public function verifyRawSignature(string $agentId, string $secret, string $body, string $providedSignature): bool;
}

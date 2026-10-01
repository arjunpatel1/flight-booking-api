<?php

namespace Tests\Unit\Printer;

use Modules\Printer\Services\AgentSignature\AgentSignatureService;
use PHPUnit\Framework\TestCase;

class AgentSignatureServiceTest extends TestCase
{
    public function test_fresh_signature_binds_agent_timestamp_nonce_and_body(): void
    {
        $service = new AgentSignatureService();
        $signature = $service->generateFreshSignature(
            'AGENT-ONE',
            'secret',
            '{"status":"online"}',
            '1784860000',
            '52ca7ee7e6ae4ca9ba81e7bd19124e93',
        );

        $this->assertTrue($service->verifyFreshSignature(
            'AGENT-ONE',
            'secret',
            '{"status":"online"}',
            '1784860000',
            '52ca7ee7e6ae4ca9ba81e7bd19124e93',
            $signature,
        ));
        $this->assertFalse($service->verifyFreshSignature(
            'AGENT-TWO',
            'secret',
            '{"status":"online"}',
            '1784860000',
            '52ca7ee7e6ae4ca9ba81e7bd19124e93',
            $signature,
        ));
        $this->assertFalse($service->verifyFreshSignature(
            'AGENT-ONE',
            'secret',
            '{"status":"offline"}',
            '1784860000',
            '52ca7ee7e6ae4ca9ba81e7bd19124e93',
            $signature,
        ));
    }
}

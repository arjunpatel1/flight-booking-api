<?php

namespace Modules\User\Services\CustomerOtp;

interface CustomerOtpSender
{
    public function send(string $channel, string $recipient, string $otp, int $ttlMinutes, int $tenantId, string $restaurantName): ?string;
}

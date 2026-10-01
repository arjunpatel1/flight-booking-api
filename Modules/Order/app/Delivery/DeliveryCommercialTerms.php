<?php

namespace Modules\Order\Delivery;

final class DeliveryCommercialTerms
{
    public function walletGstRate(): float
    {
        return round(min(100, max(0, (float) setting('delivery_wallet_gst_rate', 18))), 2);
    }

    public function platformFeePerOrder(): float
    {
        return round(max(0, (float) setting('delivery_platform_fee_per_order', 1)), 2);
    }

    public function walletTopUp(float $walletCredit): array
    {
        $credit = round(max(0, $walletCredit), 2);
        $rate = $this->walletGstRate();
        $gst = round($credit * $rate / 100, 2);

        return ['wallet_credit' => $credit, 'gst_rate' => $rate, 'gst_amount' => $gst,
            'gross_amount' => round($credit + $gst, 2)];
    }

    public function walletTopUpFromPayable(float $payable): array
    {
        return self::calculateWalletTopUpFromPayable($payable, $this->walletGstRate());
    }

    public static function calculateWalletTopUpFromPayable(float $payable, float $gstRate): array
    {
        $gross = round(max(0, $payable), 2);
        $rate = round(min(100, max(0, $gstRate)), 2);
        $credit = round($gross / (1 + ($rate / 100)), 2);
        $gst = round($gross - $credit, 2);

        return ['wallet_credit' => $credit, 'gst_rate' => $rate, 'gst_amount' => $gst,
            'gross_amount' => $gross];
    }

    public function customerFee(float $providerCost): float
    {
        return round(max(0, $providerCost) + $this->platformFeePerOrder(), 2);
    }
}

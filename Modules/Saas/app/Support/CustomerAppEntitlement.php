<?php

namespace Modules\Saas\Support;

final class CustomerAppEntitlement
{
    public const APP = 'customer_app';
    public const BUILD = 'customer_app_build';
    public const AAB = 'customer_app_aab';

    public const ALL = [self::APP, self::BUILD, self::AAB];

    private function __construct()
    {
    }
}

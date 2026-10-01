<?php

namespace Modules\Loyalty\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Loyalty\Services\Loyalty\LoyaltyService;
use Modules\Loyalty\Services\Loyalty\LoyaltyServiceInterface;
use Modules\Loyalty\Services\LoyaltyCustomer\LoyaltyCustomerService;
use Modules\Loyalty\Services\LoyaltyCustomer\LoyaltyCustomerServiceInterface;
use Modules\Loyalty\Services\LoyaltyGift\LoyaltyGiftService;
use Modules\Loyalty\Services\LoyaltyGift\LoyaltyGiftServiceInterface;
use Modules\Loyalty\Services\LoyaltyProgram\LoyaltyProgramService;
use Modules\Loyalty\Services\LoyaltyProgram\LoyaltyProgramServiceInterface;
use Modules\Loyalty\Services\LoyaltyPromotion\LoyaltyPromotionService;
use Modules\Loyalty\Services\LoyaltyPromotion\LoyaltyPromotionServiceInterface;
use Modules\Loyalty\Services\LoyaltyReward\LoyaltyRewardService;
use Modules\Loyalty\Services\LoyaltyReward\LoyaltyRewardServiceInterface;
use Modules\Loyalty\Services\LoyaltyTier\LoyaltyTierService;
use Modules\Loyalty\Services\LoyaltyTier\LoyaltyTierServiceInterface;
use Modules\Loyalty\Services\LoyaltyTransaction\LoyaltyTransactionService;
use Modules\Loyalty\Services\LoyaltyTransaction\LoyaltyTransactionServiceInterface;

class DeferredLoyaltyServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: LoyaltyProgramServiceInterface::class,
            concrete: fn($app) => $app->make(LoyaltyProgramService::class)
        );

        $this->app->singleton(
            abstract: LoyaltyTierServiceInterface::class,
            concrete: fn($app) => $app->make(LoyaltyTierService::class)
        );

        $this->app->singleton(
            abstract: LoyaltyCustomerServiceInterface::class,
            concrete: fn($app) => $app->make(LoyaltyCustomerService::class)
        );

        $this->app->singleton(
            abstract: LoyaltyTransactionServiceInterface::class,
            concrete: fn($app) => $app->make(LoyaltyTransactionService::class)
        );

        $this->app->singleton(
            abstract: LoyaltyRewardServiceInterface::class,
            concrete: fn($app) => $app->make(LoyaltyRewardService::class)
        );

        $this->app->singleton(
            abstract: LoyaltyPromotionServiceInterface::class,
            concrete: fn($app) => $app->make(LoyaltyPromotionService::class)
        );

        $this->app->singleton(
            abstract: LoyaltyServiceInterface::class,
            concrete: fn($app) => $app->make(LoyaltyService::class)
        );

        $this->app->singleton(
            abstract: LoyaltyGiftServiceInterface::class,
            concrete: fn($app) => $app->make(LoyaltyGiftService::class)
        );

    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            LoyaltyProgramServiceInterface::class,
            LoyaltyTierServiceInterface::class,
            LoyaltyCustomerServiceInterface::class,
            LoyaltyTransactionServiceInterface::class,
            LoyaltyRewardServiceInterface::class,
            LoyaltyPromotionServiceInterface::class,
            LoyaltyServiceInterface::class,
            LoyaltyGiftServiceInterface::class
        ];
    }
}

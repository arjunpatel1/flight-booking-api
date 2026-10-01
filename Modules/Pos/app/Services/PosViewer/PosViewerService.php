<?php

namespace Modules\Pos\Services\PosViewer;

use Modules\Pos\Services\PosViewer\Concerns\HandlesAssistantHealth;
use Modules\Pos\Services\PosViewer\Concerns\HandlesAssistantOperationalCards;
use Modules\Pos\Services\PosViewer\Concerns\HandlesAssistantPolicyCards;
use Modules\Pos\Services\PosViewer\Concerns\HandlesAssistantRecoveryCards;
use Modules\Pos\Services\PosViewer\Concerns\HandlesPerformanceCore;
use Modules\Pos\Services\PosViewer\Concerns\HandlesPerformanceDecision;
use Modules\Pos\Services\PosViewer\Concerns\HandlesPerformanceTableInfra;
use Modules\Pos\Services\PosViewer\Concerns\HandlesPerformanceWaiterKitchen;
use Modules\Pos\Services\PosViewer\Concerns\HandlesPosCatalog;
use Modules\Pos\Services\PosViewer\Concerns\HandlesPosConfiguration;
use Modules\Pos\Services\PosViewer\Concerns\HandlesPosMenu;
use Modules\Pos\Services\PosViewer\Concerns\HandlesRestaurantMemory;
use Modules\Pos\Services\PosViewer\Concerns\HandlesWaiterAssistant;
use Modules\Pos\Services\PosViewer\Concerns\HandlesWaiterDashboard;
use Modules\Pos\Services\RevenueIntelligence\RevenueIntelligenceService;
use Modules\Pricing\Services\ProductPriceResolver\ProductPriceResolverServiceInterface;

class PosViewerService implements PosViewerServiceInterface
{
    use HandlesPosConfiguration;
    use HandlesPosMenu;
    use HandlesWaiterDashboard;
    use HandlesWaiterAssistant;
    use HandlesPerformanceCore;
    use HandlesPerformanceWaiterKitchen;
    use HandlesPerformanceTableInfra;
    use HandlesPerformanceDecision;
    use HandlesAssistantOperationalCards;
    use HandlesAssistantRecoveryCards;
    use HandlesAssistantPolicyCards;
    use HandlesAssistantHealth;
    use HandlesPosCatalog;
    use HandlesRestaurantMemory;

    public function __construct(
        protected ProductPriceResolverServiceInterface $priceResolver,
        protected RevenueIntelligenceService $revenueIntelligence,
    ) {
    }

    public function revenueIntelligence(?int $branchId = null, string $period = 'today'): array
    {
        return $this->revenueIntelligence->handle($branchId, $period);
    }
}

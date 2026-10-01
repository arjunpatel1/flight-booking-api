<?php

namespace Tests\Unit\WhatsAppCenter;

use Modules\WhatsAppCenter\Services\WhatsAppBranchTransition;
use PHPUnit\Framework\TestCase;

class WhatsAppBranchTransitionTest extends TestCase
{
    public function test_only_removed_explicit_branches_are_retired(): void
    {
        $service = new WhatsAppBranchTransition;

        $this->assertSame([10], $service->removedBranchIds([10], [20], [10, 20]));
        $this->assertSame([], $service->removedBranchIds([10], [10], [10, 20]));
    }

    public function test_empty_branch_list_means_all_active_branches(): void
    {
        $service = new WhatsAppBranchTransition;

        $this->assertSame([10], $service->removedBranchIds([], [20], [10, 20]));
        $this->assertSame([], $service->removedBranchIds([10], [], [10, 20]));
    }

    public function test_ids_are_normalized_before_comparison(): void
    {
        $service = new WhatsAppBranchTransition;

        $this->assertSame([10], $service->removedBranchIds(['10', '10'], ['20'], ['10', '20']));
    }
}

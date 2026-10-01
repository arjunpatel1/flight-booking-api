<?php

namespace Modules\Core\Automation;

/**
 * Canonical automation execution lifecycle, shared consistently with Flutter/Vue.
 *
 * Pending → Eligible → Executing → Completed | Failed → RolledBack (if reversible)
 *                                                      → Expired (stale)
 */
enum AutomationExecutionState: string
{
    case Pending = 'pending';
    case Eligible = 'eligible';
    case Executing = 'executing';
    case Completed = 'completed';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
    case Expired = 'expired';
}

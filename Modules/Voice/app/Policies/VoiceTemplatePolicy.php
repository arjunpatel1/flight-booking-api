<?php

namespace Modules\Voice\Policies;

use Modules\User\Models\User;
use Modules\Voice\Models\VoiceTemplate;
use Illuminate\Auth\Access\HandlesAuthorization;

class VoiceTemplatePolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the user can view any voice templates.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('admin.voice.templates.edit');
    }

    /**
     * Determine if the user can view a specific voice template.
     */
    public function view(User $user, VoiceTemplate $template): bool
    {
        return $this->canUseTemplateBranch($user, $template)
            && $user->can('admin.voice.templates.edit');
    }

    /**
     * Determine if the user can create voice templates.
     */
    public function create(User $user): bool
    {
        return $user->can('admin.voice.templates.edit');
    }

    /**
     * Determine if the user can update a voice template.
     */
    public function update(User $user, VoiceTemplate $template): bool
    {
        return $this->canUseTemplateBranch($user, $template)
            && $user->can('admin.voice.templates.edit');
    }

    /**
     * Determine if the user can delete a voice template.
     */
    public function delete(User $user, VoiceTemplate $template): bool
    {
        return $this->canUseTemplateBranch($user, $template)
            && $user->can('admin.voice.templates.edit');
    }

    private function canUseTemplateBranch(User $user, VoiceTemplate $template): bool
    {
        $branchId = $user->assignedToBranch()
            ? $user->branch_id
            : $user->effective_branch?->id;

        return (int) $branchId === (int) $template->branch_id;
    }
}

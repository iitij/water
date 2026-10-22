<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Square;
use Illuminate\Auth\Access\HandlesAuthorization;

class SquarePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Square');
    }

    public function view(AuthUser $authUser, Square $square): bool
    {
        return $authUser->can('View:Square');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Square');
    }

    public function update(AuthUser $authUser, Square $square): bool
    {
        return $authUser->can('Update:Square');
    }

    public function delete(AuthUser $authUser, Square $square): bool
    {
        return $authUser->can('Delete:Square');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Square');
    }

    public function restore(AuthUser $authUser, Square $square): bool
    {
        return $authUser->can('Restore:Square');
    }

    public function forceDelete(AuthUser $authUser, Square $square): bool
    {
        return $authUser->can('ForceDelete:Square');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Square');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Square');
    }

    public function replicate(AuthUser $authUser, Square $square): bool
    {
        return $authUser->can('Replicate:Square');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Square');
    }

}
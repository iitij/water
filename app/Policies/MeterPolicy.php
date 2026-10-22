<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Meter;
use Illuminate\Auth\Access\HandlesAuthorization;

class MeterPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Meter');
    }

    public function view(AuthUser $authUser, Meter $meter): bool
    {
        return $authUser->can('View:Meter');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Meter');
    }

    public function update(AuthUser $authUser, Meter $meter): bool
    {
        return $authUser->can('Update:Meter');
    }

    public function delete(AuthUser $authUser, Meter $meter): bool
    {
        return $authUser->can('Delete:Meter');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Meter');
    }

    public function restore(AuthUser $authUser, Meter $meter): bool
    {
        return $authUser->can('Restore:Meter');
    }

    public function forceDelete(AuthUser $authUser, Meter $meter): bool
    {
        return $authUser->can('ForceDelete:Meter');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Meter');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Meter');
    }

    public function replicate(AuthUser $authUser, Meter $meter): bool
    {
        return $authUser->can('Replicate:Meter');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Meter');
    }

}
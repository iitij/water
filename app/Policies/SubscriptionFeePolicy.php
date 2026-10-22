<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\SubscriptionFee;
use Illuminate\Auth\Access\HandlesAuthorization;

class SubscriptionFeePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SubscriptionFee');
    }

    public function view(AuthUser $authUser, SubscriptionFee $subscriptionFee): bool
    {
        return $authUser->can('View:SubscriptionFee');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SubscriptionFee');
    }

    public function update(AuthUser $authUser, SubscriptionFee $subscriptionFee): bool
    {
        return $authUser->can('Update:SubscriptionFee');
    }

    public function delete(AuthUser $authUser, SubscriptionFee $subscriptionFee): bool
    {
        return $authUser->can('Delete:SubscriptionFee');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SubscriptionFee');
    }

    public function restore(AuthUser $authUser, SubscriptionFee $subscriptionFee): bool
    {
        return $authUser->can('Restore:SubscriptionFee');
    }

    public function forceDelete(AuthUser $authUser, SubscriptionFee $subscriptionFee): bool
    {
        return $authUser->can('ForceDelete:SubscriptionFee');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SubscriptionFee');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SubscriptionFee');
    }

    public function replicate(AuthUser $authUser, SubscriptionFee $subscriptionFee): bool
    {
        return $authUser->can('Replicate:SubscriptionFee');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SubscriptionFee');
    }

}
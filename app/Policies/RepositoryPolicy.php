<?php

namespace App\Policies;

use App\Models\Repository;
use App\Models\User;

class RepositoryPolicy
{
    public function view(User $user, Repository $repository): bool
    {
        return $repository->installation?->user_id === $user->id;
    }

    public function scan(User $user, Repository $repository): bool
    {
        return $this->view($user, $repository)
            && ! $repository->isRemoved()
            && $repository->installation?->isUsable() === true;
    }
}

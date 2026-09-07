<?php

namespace Modules\Import\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Import\Models\ImportBatch;

class ImportBatchPolicy
{
    use HandlesAuthorization;

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, ImportBatch $batch): bool
    {
        return $user->isAdmin();
    }
}

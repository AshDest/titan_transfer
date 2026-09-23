<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\OperationRecorded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

final class OperationNotifications
{
    /** Store in the operation's transaction: rolled-back operations never notify. */
    public function record(Model $subject, User $actor): void
    {
        User::permission(OperationRecorded::PERMISSIONS[$subject::class])
            ->with(['branch', 'roles.permissions', 'permissions'])->chunkById(100, function ($users) use ($subject, $actor): void {
                foreach ($users as $user) {
                    if ($user->canAccessApplication() && Gate::forUser($user)->allows('view', $subject)) {
                        $user->notify(new OperationRecorded($subject, $actor));
                    }
                }
            });
    }
}

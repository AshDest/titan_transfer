<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\RecordStatus;
use App\Enums\Role;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public function notifications(): MorphMany
    {
        return $this->morphMany(InboxNotification::class, 'notifiable')->latest()->orderByDesc('id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isCashier(): bool
    {
        return $this->hasAnyRole([Role::SourceCashier->value, Role::DestinationCashier->value]);
    }

    public function canAccessBranch(Branch $branch): bool
    {
        return $this->can(Permission::BranchesViewAll->value) || $this->branch_id === $branch->id;
    }

    public function canAccessApplication(): bool
    {
        return $this->can(Permission::DashboardView->value)
            && ($this->can(Permission::BranchesViewAll->value)
                || $this->branch?->status === RecordStatus::Active);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}

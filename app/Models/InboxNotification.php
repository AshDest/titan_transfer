<?php

namespace App\Models;

use App\Notifications\OperationRecorded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Notifications\DatabaseNotification;

class InboxNotification extends DatabaseNotification
{
    protected $table = 'notifications';

    protected static function booted(): void
    {
        static::creating(function (self $notification): void {
            $notification->subject_type = $notification->data['subject_type'] ?? null;
            $notification->subject_id = $notification->data['subject_id'] ?? null;
        });
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** Recheck current access, including after an agency or permission change. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->canAccessApplication()) {
            return $query->whereRaw('1 = 0');
        }
        $types = array_keys(array_filter(OperationRecorded::PERMISSIONS, fn (string $permission): bool => $user->can($permission)));
        if ($types === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHasMorph('subject', $types, function (Builder $subject, string $type) use ($user): void {
            if ($type === Transfer::class) {
                $subject->visibleTo($user);
            } elseif (! $user->can('branches.view-all')) {
                if ($type === Branch::class) {
                    $subject->whereKey($user->branch_id);
                } elseif (in_array($type, [Customer::class, Beneficiary::class], true)) {
                    $subject->where('branch_id', $user->branch_id);
                }
            }
        });
    }

    public function destinationUrl(): string
    {
        $record = $this->subject;

        return match ($record::class) {
            Transfer::class => route('transfers.show', $record),
            Customer::class => route('customers.index', ['q' => $record->customer_number]),
            Beneficiary::class => route('beneficiaries.index', ['q' => $record->last_name]),
            Branch::class => route('branches.index', ['q' => $record->code]),
            ExchangeRate::class => route('exchange-rates.index', ['q' => $record->from_currency]),
            TransferFeeRule::class => route('fee-rules.index', ['q' => $record->name]),
        };
    }
}

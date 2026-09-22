<?php

namespace App\Enums;

enum TransferStatus: string
{
    case Draft = 'DRAFT';
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case ReadyForPayout = 'READY_FOR_PAYOUT';
    case Paid = 'PAID';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';
    case Reversed = 'REVERSED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon', self::Pending => 'En attente de validation',
            self::Approved => 'Approuvé', self::ReadyForPayout => 'Prêt au retrait',
            self::Paid => 'Payé', self::Rejected => 'Rejeté', self::Cancelled => 'Annulé', self::Reversed => 'Extourné',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Pending],
            self::Pending => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::ReadyForPayout, self::Cancelled],
            self::ReadyForPayout => [self::Paid],
            self::Paid => [self::Reversed],
            self::Rejected, self::Cancelled, self::Reversed => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }
}

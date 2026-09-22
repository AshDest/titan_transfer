<?php

namespace Tests\Unit;

use App\Enums\TransferStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TransferStatusTest extends TestCase
{
    #[DataProvider('transitions')]
    public function test_status_transition_rules(TransferStatus $from, TransferStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    public static function transitions(): array
    {
        return [
            'draft submission' => [TransferStatus::Draft, TransferStatus::Pending, true],
            'approval' => [TransferStatus::Pending, TransferStatus::Approved, true],
            'ready' => [TransferStatus::Approved, TransferStatus::ReadyForPayout, true],
            'payment' => [TransferStatus::ReadyForPayout, TransferStatus::Paid, true],
            'rejection' => [TransferStatus::Pending, TransferStatus::Rejected, true],
            'cancel pending' => [TransferStatus::Pending, TransferStatus::Cancelled, true],
            'cancel approved' => [TransferStatus::Approved, TransferStatus::Cancelled, true],
            'controlled reversal' => [TransferStatus::Paid, TransferStatus::Reversed, true],
            'no direct draft payment' => [TransferStatus::Draft, TransferStatus::Paid, false],
            'no unapproved payment' => [TransferStatus::Pending, TransferStatus::Paid, false],
            'no duplicate payment' => [TransferStatus::Paid, TransferStatus::Paid, false],
            'no paid reset' => [TransferStatus::Paid, TransferStatus::Pending, false],
            'no paid cancellation' => [TransferStatus::Paid, TransferStatus::Cancelled, false],
            'no cancelled payment' => [TransferStatus::Cancelled, TransferStatus::Paid, false],
            'no rejected payment' => [TransferStatus::Rejected, TransferStatus::Paid, false],
            'no reversed payment' => [TransferStatus::Reversed, TransferStatus::Paid, false],
        ];
    }
}

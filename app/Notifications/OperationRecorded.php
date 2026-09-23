<?php

namespace App\Notifications;

use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Transfer;
use App\Models\TransferFeeRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

class OperationRecorded extends Notification
{
    public const array PERMISSIONS = [
        Transfer::class => 'transfers.view', Customer::class => 'customers.view',
        Beneficiary::class => 'beneficiaries.view', Branch::class => 'branches.view',
        ExchangeRate::class => 'exchange-rates.view', TransferFeeRule::class => 'fee-rules.view',
    ];

    public function __construct(public Model $subject, public User $actor) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $record = $this->subject;
        [$title, $message] = match ($record::class) {
            Transfer::class => ['Transfert enregistré', $record->reference.' : '.$record->amount_received.' '.$record->source_currency.' encaissés. En attente de validation.'],
            Customer::class => ['Nouveau client', $record->first_name.' '.$record->last_name.' a été enregistré.'],
            Beneficiary::class => ['Nouveau bénéficiaire', $record->first_name.' '.$record->last_name.' a été enregistré.'],
            Branch::class => ['Nouvelle agence', 'L’agence '.$record->name.' ('.$record->code.') a été créée.'],
            ExchangeRate::class => ['Taux de change enregistré', '1 '.$record->from_currency.' = '.$record->rate.' '.$record->to_currency.'. Nouvelle version '.($record->active ? 'active' : 'inactive').'.'],
            TransferFeeRule::class => ['Règle de frais enregistrée', $record->name.' : '.$record->percentage.' % en '.$record->source_currency.'.'],
        };

        return ['subject_type' => $record->getMorphClass(), 'subject_id' => $record->getKey(),
            'title' => $title, 'message' => $message, 'actor' => $this->actor->name];
    }
}

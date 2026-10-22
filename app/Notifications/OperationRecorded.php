<?php

namespace App\Notifications;

use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Transfer;
use App\Models\TransferFeeRule;
use App\Models\User;
use App\Services\DecimalFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

class OperationRecorded extends Notification
{
    public const array PERMISSIONS = [
        Transfer::class => 'transfers.view', Customer::class => 'customers.view',
        Beneficiary::class => 'beneficiaries.view', Branch::class => 'branches.view',
        ExchangeRate::class => 'exchange-rates.view', TransferFeeRule::class => 'fee-rules.view',
        User::class => 'users.manage',
    ];

    public function __construct(public Model $subject, public User $actor, public string $event = 'created') {}

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
            Transfer::class => match ($this->event) {
                'approved' => ['Transfert approuvé', $record->reference.' a été validé et transmis à l’agence de retrait.'],
                'rejected' => ['Transfert rejeté', $record->reference.' a été rejeté. Consultez le motif dans le détail du transfert.'],
                default => ['Transfert enregistré', $record->reference.' : '.DecimalFormatter::money($record->amount_received).' '.$record->source_currency.' encaissés. En attente de validation.'],
            },
            Customer::class => $this->event === 'updated'
                ? ['Client modifié', 'La fiche de '.$record->first_name.' '.$record->last_name.' a été modifiée.']
                : ['Nouveau client', $record->first_name.' '.$record->last_name.' a été enregistré.'],
            Beneficiary::class => ['Nouveau bénéficiaire', $record->first_name.' '.$record->last_name.' a été enregistré.'],
            Branch::class => $this->event === 'updated'
                ? ['Agence modifiée', 'L’agence '.$record->name.' ('.$record->code.') a été modifiée.']
                : ['Nouvelle agence', 'L’agence '.$record->name.' ('.$record->code.') a été créée.'],
            User::class => $this->event === 'phone_updated'
                ? ['Téléphone utilisateur modifié', 'Le téléphone de connexion de '.$record->name.' a été modifié.']
                : ['Nouvel utilisateur', 'Le compte de '.$record->name.' a été créé.'],
            ExchangeRate::class => ['Taux de change enregistré', '1 '.$record->from_currency.' = '.DecimalFormatter::rate($record->rate).' '.$record->to_currency.'. Nouvelle version '.($record->active ? 'active' : 'inactive').'.'],
            TransferFeeRule::class => ['Règle de frais enregistrée', $record->name.' : '.DecimalFormatter::percentage($record->percentage).' % en '.$record->source_currency.'.'],
        };

        return ['subject_type' => $record->getMorphClass(), 'subject_id' => $record->getKey(),
            'event' => $this->event, 'title' => $title, 'message' => $message, 'actor' => $this->actor->name];
    }
}

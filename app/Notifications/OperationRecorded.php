<?php

namespace App\Notifications;

use App\Enums\CashMovementType;
use App\Enums\FeeType;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\CashMovement;
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
        CashMovement::class => 'cash.view',
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
            CashMovement::class => $record->type === CashMovementType::CashIn
                ? ['Caisse approvisionnée', DecimalFormatter::money($record->amount).' '.$record->currency.' reçus de '.$record->provider_name.' · '.$record->account->branch->name.'.']
                : ['Sortie de caisse enregistrée', DecimalFormatter::money($record->amount).' '.$record->currency.' remis à '.$record->recipient_name.' · '.$record->account->branch->name.'.'],
            Transfer::class => match ($this->event) {
                'paid' => ['Transfert payé', $this->actor->name.' a confirmé la remise de '.DecimalFormatter::money($record->payout_amount).' '.$record->destination_currency.' au bénéficiaire à '.$record->destinationBranch->name.' · '.$record->reference.' · Départ : '.$record->sourceBranch->name.'.'],
                'approved' => $notifiable instanceof User && $notifiable->branch_id === $record->destination_branch_id && $notifiable->can('pay', $record)
                    ? ['Transfert à payer', $record->reference.' reçu de '.$record->sourceBranch->name.' : '.DecimalFormatter::money($record->payout_amount).' '.$record->destination_currency.' à remettre au bénéficiaire. Ouvrez le transfert pour confirmer le paiement.']
                    : ['Transfert approuvé', $record->reference.' a été validé et transmis à '.$record->destinationBranch->name.'.'],
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
            ExchangeRate::class => match ($this->event) {
                'activated' => ['Taux de change activé', '1 '.$record->from_currency.' = '.DecimalFormatter::rate($record->rate).' '.$record->to_currency.'. Les autres taux de cette paire ont été désactivés.'],
                'deactivated' => ['Taux de change désactivé', 'Le taux '.$record->from_currency.' → '.$record->to_currency.' a été désactivé.'],
                default => ['Taux de change enregistré', '1 '.$record->from_currency.' = '.DecimalFormatter::rate($record->rate).' '.$record->to_currency.'. Nouvelle version '.($record->active ? 'active' : 'inactive').'.'],
            },
            TransferFeeRule::class => $this->event === 'deleted'
                ? ['Règle de frais supprimée', $record->name.' a été retirée des tarifs disponibles.']
                : ['Règle de frais enregistrée', $record->name.' : '.($record->fee_type === FeeType::Fixed
                ? DecimalFormatter::money($record->fixed_amount).' '.$record->source_currency.' (montant fixe).'
                : DecimalFormatter::percentage($record->percentage).' % en '.$record->source_currency.'.')],
        };

        return ['subject_type' => $record->getMorphClass(), 'subject_id' => $record->getKey(),
            'event' => $this->event, 'title' => $title, 'message' => $message, 'actor' => $this->actor->name];
    }
}

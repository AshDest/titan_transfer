<?php

namespace App\Services;

use App\DTOs\TransferQuote;
use App\Enums\FeeType;
use App\Models\Branch;
use App\Models\ExchangeRate;
use App\Models\TransferFeeRule;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

final class TransferPricing
{
    public function __construct(private TransferCalculator $calculator) {}

    /** @return array{quote: TransferQuote, rate: ?ExchangeRate, fee: TransferFeeRule} */
    public function quote(Branch $source, Branch $destination, string $amount): array
    {
        $at = now('UTC');
        $rate = null;
        if ($source->default_currency !== $destination->default_currency) {
            $rate = ExchangeRate::query()->where('from_currency', $source->default_currency)
                ->where('to_currency', $destination->default_currency)->where('active', true)
                ->where('effective_from', '<=', $at)
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $at))
                ->orderByDesc('effective_from')->orderByDesc('id')->first();
            if (! $rate) {
                throw ValidationException::withMessages(['destination_branch_id' => 'Aucun taux de change actif et valide actuellement pour '.$source->default_currency.' → '.$destination->default_currency.'. Contactez votre administrateur pour vérifier les devises, l’activation et les dates de validité (UTC).']);
            }
        }
        $amountValue = BigDecimal::of($amount);
        $rules = TransferFeeRule::query()->where('source_currency', $source->default_currency)
            ->where(fn ($query) => $query->whereNull('source_branch_id')->orWhere('source_branch_id', $source->id))
            ->where(fn ($query) => $query->whereNull('destination_branch_id')->orWhere('destination_branch_id', $destination->id))
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $at))
            ->orderByRaw('CASE WHEN effective_from IS NULL THEN 1 ELSE 0 END')->orderByDesc('effective_from')->orderByDesc('id')->get()
            ->unique(fn ($rule) => implode('|', [$rule->source_branch_id, $rule->destination_branch_id, $rule->minimum_amount ?? '0.00', $rule->maximum_amount ?? '*']))
            ->filter(fn ($rule) => $amountValue->isGreaterThanOrEqualTo($rule->minimum_amount ?? '0')
                && ($rule->maximum_amount === null || $amountValue->isLessThanOrEqualTo($rule->maximum_amount)));
        $specificity = fn ($rule): int => (int) ($rule->source_branch_id !== null) + (int) ($rule->destination_branch_id !== null);
        $rank = $rules->max($specificity);
        $matches = $rules->filter(fn ($rule) => $specificity($rule) === $rank && $rule->active);
        if ($matches->count() !== 1 || ! $matches->first()->active) {
            throw ValidationException::withMessages(['amount_received' => $matches->count() > 1
                ? 'Plusieurs règles de frais se chevauchent. Contactez votre administrateur.'
                : 'Aucune règle de frais active pour ce montant et ce corridor.']);
        }
        $fee = $matches->first();

        return ['quote' => $this->calculator->calculate($amount, $fee->fee_type === FeeType::Fixed ? $fee->fixed_amount : $fee->percentage, $rate?->rate ?? '1.000000', $fee->fee_type), 'rate' => $rate, 'fee' => $fee];
    }
}

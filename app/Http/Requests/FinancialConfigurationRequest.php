<?php

namespace App\Http\Requests;

use App\Models\ExchangeRate;
use App\Models\TransferFeeRule;
use Illuminate\Foundation\Http\FormRequest;

class FinancialConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', $this->routeIs('exchange-rates.*') ? ExchangeRate::class : TransferFeeRule::class);
    }

    public function rules(): array
    {
        return [];
    }

    /**
     * Browser datetime-local values are entered in UTC, as indicated by the form.
     * The domain actions validate both dates and all financial values.
     *
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        $data = $this->all();
        foreach (['effective_from', 'effective_until'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = str_replace('T', ' ', $data[$field]);
                if (strlen($data[$field]) === 16) {
                    $data[$field] .= ':00';
                }
            }
        }

        return $data;
    }
}

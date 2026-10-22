<?php

namespace App\Actions\Branches;

use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\User;
use App\Services\OperationNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UpdateBranch
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, Branch $branch, array $input): Branch
    {
        Gate::forUser($actor)->authorize('update', $branch);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'regex:/^[A-Z0-9]{2,12}$/', Rule::unique('branches', 'code')->ignore($branch)],
            'country' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'default_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/', 'not_in:EAD'],
            'timezone' => ['required', 'timezone:all', 'max:64'],
            'status' => ['required', Rule::enum(RecordStatus::class)],
        ], ['default_currency.not_in' => 'Le code du dirham des Émirats est AED, et non EAD.', 'code.unique' => 'Ce code est déjà utilisé par une autre agence.', 'status.enum' => 'Sélectionnez un statut valide.'])->validate();

        return DB::transaction(function () use ($actor, $branch, $data): Branch {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            if ($data['default_currency'] !== $branch->default_currency && $branch->hasFinancialRecords()) {
                throw ValidationException::withMessages(['default_currency' => 'La devise ne peut plus être modifiée : cette agence possède une caisse, des transferts ou des règles de frais.']);
            }
            $branch->fill($data)->save();
            app(OperationNotifications::class)->record($branch, $actor, 'updated');

            return $branch;
        });
    }
}

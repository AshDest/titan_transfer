<?php

namespace App\Actions\Branches;

use App\Models\Branch;
use App\Models\User;
use App\Services\OperationNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CreateBranch
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, array $input): Branch
    {
        Gate::forUser($actor)->authorize('create', Branch::class);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'regex:/^[A-Z0-9]{2,12}$/', Rule::unique('branches', 'code')],
            'country' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'default_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/', 'not_in:EAD'],
            'timezone' => ['required', 'timezone:all', 'max:64'],
        ], ['default_currency.not_in' => 'Le code du dirham des Émirats est AED, et non EAD.'])->validate();

        return DB::transaction(function () use ($actor, $data): Branch {
            $branch = Branch::query()->create($data)->refresh();
            app(OperationNotifications::class)->record($branch, $actor);

            return $branch;
        });
    }
}

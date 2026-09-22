<?php

namespace App\Actions\Branches;

use App\Models\Branch;
use App\Models\User;
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
            'default_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['required', 'timezone:all', 'max:64'],
        ])->validate();

        return Branch::query()->create($data)->refresh();
    }
}

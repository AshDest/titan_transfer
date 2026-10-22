<?php

namespace App\Actions\Branches;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DeleteBranch
{
    public function handle(User $actor, Branch $branch): void
    {
        Gate::forUser($actor)->authorize('delete', $branch);
        $message = 'Suppression impossible : des utilisateurs, clients, bénéficiaires ou données financières dépendent de cette agence. Vous pouvez la désactiver.';
        try {
            DB::transaction(function () use ($branch, $message): void {
                $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
                if ($branch->hasDependencies()) {
                    throw ValidationException::withMessages(['deletion' => $message]);
                }
                $branch->delete();
            });
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23000', '23503'], true)) {
                throw ValidationException::withMessages(['deletion' => $message]);
            }
            throw $exception;
        }
    }
}

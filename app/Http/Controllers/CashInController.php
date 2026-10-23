<?php

namespace App\Http\Controllers;

use App\Actions\Cash\RecordCashIn;
use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\CashAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CashInController extends Controller
{
    public function create(Request $request): View
    {
        Gate::authorize('createFunding', CashAccount::class);
        $branches = Branch::with('cashAccounts')->where('status', RecordStatus::Active)
            ->when(! $request->user()->canViewAllCashAccounts(), fn ($query) => $query->whereKey($request->user()->branch_id))
            ->orderBy('name')->get();

        return view('treasury.fund', ['branches' => $branches, 'submissionKey' => (string) Str::uuid()]);
    }

    public function store(Request $request, RecordCashIn $action): RedirectResponse
    {
        Gate::authorize('createFunding', CashAccount::class);
        $data = $request->validate(['branch_id' => ['required', 'integer']]);
        $branch = Branch::findOrFail($data['branch_id']);
        $movement = $action->handle($request->user(), $branch, $request->all());

        return redirect()->route('treasury.index', ['branch_id' => $branch->id])
            ->with('status', 'Approvisionnement n° '.$movement->id.' enregistré. Le solde a été mis à jour.');
    }
}

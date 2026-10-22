<?php

namespace App\Http\Controllers;

use App\Actions\Cash\RecordCashOut;
use App\Enums\CashMovementType;
use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\CashMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TreasuryController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', CashAccount::class);
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer'], 'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'type' => ['nullable', Rule::enum(CashMovementType::class)], 'q' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'movement_id' => ['nullable', 'integer'],
        ], ['date_to.after_or_equal' => 'La date de fin doit être égale ou postérieure à la date de début.']);
        $user = $request->user();
        $branches = Branch::with('cashAccounts')->when(! $user->canViewAllCashAccounts(), fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')->get();
        if (! empty($filters['branch_id'])) {
            abort_unless($branches->contains('id', (int) $filters['branch_id']), 404);
        }
        $balances = $branches->when(! empty($filters['branch_id']), fn ($items) => $items->where('id', $filters['branch_id']))
            ->flatMap(function (Branch $branch) {
                $accounts = $branch->cashAccounts->isEmpty() ? collect([null]) : $branch->cashAccounts;

                return $accounts->map(fn ($account): array => ['branch' => $branch, 'account' => $account,
                    'currency' => $account?->currency ?? $branch->default_currency, 'balance' => $account?->balance ?? '0.00']);
            })->when(! empty($filters['currency']), fn ($items) => $items->where('currency', $filters['currency']));
        $query = CashMovement::visibleTo($user)->with(['account.branch', 'createdBy', 'transfer'])
            ->when(! empty($filters['branch_id']), fn ($query) => $query->whereHas('account', fn ($account) => $account->where('branch_id', $filters['branch_id'])))
            ->when(! empty($filters['currency']), fn ($query) => $query->where('currency', $filters['currency']))
            ->when(! empty($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->when(! empty($filters['date_from']), fn ($query) => $query->where('occurred_on', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($query) => $query->where('occurred_on', '<=', $filters['date_to']))
            ->when(! empty($filters['movement_id']), fn ($query) => $query->whereKey($filters['movement_id']));
        $search = trim($filters['q'] ?? '');
        $query->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
            ->where('recipient_name', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%')
            ->orWhereHas('transfer', fn ($transfer) => $transfer->where('reference', 'like', '%'.$search.'%'))));
        $records = $query->orderByDesc('occurred_on')->orderByDesc('id')->paginate(25)->withQueryString();
        $timezone = $user->branch?->timezone ?? 'UTC';

        return view('treasury.index', compact('branches', 'balances', 'records', 'timezone'));
    }

    public function create(Request $request): View
    {
        Gate::authorize('createWithdrawal', CashAccount::class);
        $accounts = CashAccount::visibleTo($request->user())->with('branch')->where('status', RecordStatus::Active)
            ->whereHas('branch', fn ($query) => $query->where('status', RecordStatus::Active))->orderBy('branch_id')->orderBy('currency')->get();

        return view('treasury.create', ['accounts' => $accounts, 'submissionKey' => (string) Str::uuid()]);
    }

    public function store(Request $request, RecordCashOut $action): RedirectResponse
    {
        Gate::authorize('createWithdrawal', CashAccount::class);
        $data = $request->validate(['cash_account_id' => ['required', 'integer']]);
        $account = CashAccount::findOrFail($data['cash_account_id']);
        $movement = $action->handle($request->user(), $account, $request->all());

        return redirect()->route('treasury.index', ['branch_id' => $account->branch_id])
            ->with('status', 'Sortie de caisse n° '.$movement->id.' enregistrée. Le solde a été mis à jour.');
    }
}

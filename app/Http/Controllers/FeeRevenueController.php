<?php

namespace App\Http\Controllers;

use App\Actions\Cash\WithdrawTransferFees;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\FeeWithdrawal;
use App\Services\FeeRevenue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeeRevenueController extends Controller
{
    public function index(Request $request, FeeRevenue $revenue): View
    {
        Gate::authorize('viewFeeRevenue', CashAccount::class);
        $filters = $this->filters($request);
        $query = $revenue->query($request->user(), $filters);
        $totals = $revenue->totals($query);
        $records = $query->latest('id')->paginate(20)->withQueryString();
        $branches = Branch::query()->when(! $request->user()->canViewAllCashAccounts(), fn ($query) => $query->whereKey($request->user()->branch_id))->orderBy('name')->get();
        $withdrawals = FeeWithdrawal::query()->where('period_from', '<=', $filters['date_to'])->where('period_to', '>=', $filters['date_from'])
            ->whereHas('movement', fn ($query) => $query->visibleTo($request->user())
                ->when(! empty($filters['currency']), fn ($query) => $query->where('currency', $filters['currency']))
                ->when(! empty($filters['branch_id']), fn ($query) => $query->whereHas('account', fn ($account) => $account->where('branch_id', $filters['branch_id']))))
            ->with(['movement.account.branch', 'movement.createdBy'])->latest('id')->paginate(10, ['*'], 'withdrawals_page')->withQueryString();

        return view('fee-revenue.index', compact('filters', 'totals', 'records', 'branches', 'withdrawals', 'revenue'));
    }

    public function export(Request $request, FeeRevenue $revenue): StreamedResponse
    {
        Gate::authorize('viewFeeRevenue', CashAccount::class);
        $filters = $this->filters($request);
        $query = $revenue->query($request->user(), $filters);

        return response()->streamDownload(function () use ($query, $revenue): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Référence', 'Date d’encaissement', 'Agence', 'Statut', 'Devise', 'Montant encaissé', 'Frais générés', 'Déjà retirés', 'Frais restants'], ';', '"', '');
            foreach ($query->lazyById(500) as $transfer) {
                $amounts = $revenue->amounts($transfer);
                $row = [$transfer->reference, $transfer->depositMovement->occurred_on->format('d/m/Y'), $transfer->sourceBranch->name,
                    $transfer->status->label(), $transfer->source_currency, str_replace('.', ',', $transfer->amount_received),
                    ...array_map(fn (string $amount): string => str_replace('.', ',', $amount), array_values($amounts))];
                fputcsv($stream, array_map(fn (string $value): string => preg_match('/^\s*[=+\-@]|^[\t\r\n]/u', $value) ? "'".$value : $value, $row), ';', '"', '');
            }
            fclose($stream);
        }, 'frais-transferts-'.$filters['date_from'].'-'.$filters['date_to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    public function create(Request $request, FeeRevenue $revenue): View
    {
        Gate::authorize('withdrawFeeRevenue', CashAccount::class);
        $filters = $this->filters($request);
        $data = $request->validate(['cash_account_id' => ['required', 'integer']]);
        $account = CashAccount::visibleTo($request->user())->with('branch')->findOrFail($data['cash_account_id']);
        Gate::authorize('withdrawFees', $account);
        $filters = [...$filters, 'branch_id' => $account->branch_id, 'currency' => $account->currency];
        $summary = $revenue->totals($revenue->query($request->user(), $filters))[$account->id]
            ?? ['fees' => '0.00', 'withdrawn' => '0.00', 'available' => '0.00'];

        return view('fee-revenue.create', ['account' => $account, 'filters' => $filters, 'summary' => $summary, 'submissionKey' => (string) Str::uuid()]);
    }

    public function store(Request $request, WithdrawTransferFees $action): RedirectResponse
    {
        Gate::authorize('withdrawFeeRevenue', CashAccount::class);
        $data = $request->validate(['cash_account_id' => ['required', 'integer']]);
        $account = CashAccount::visibleTo($request->user())->findOrFail($data['cash_account_id']);
        $withdrawal = $action->handle($request->user(), $account, $request->all());

        return redirect()->route('fee-revenue.index', ['date_from' => $withdrawal->period_from, 'date_to' => $withdrawal->period_to,
            'branch_id' => $account->branch_id, 'currency' => $account->currency])
            ->with('status', 'Retrait des frais enregistré. Le solde de caisse a été mis à jour.');
    }

    /** @return array{date_from: string, date_to: string, branch_id?: mixed, currency?: ?string} */
    private function filters(Request $request): array
    {
        $today = now($request->user()->branch?->timezone ?? 'UTC');
        $request->mergeIfMissing(['date_from' => $today->copy()->startOfMonth()->toDateString(), 'date_to' => $today->toDateString()]);
        $filters = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'], 'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer'], 'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/D'],
        ], ['date_to.after_or_equal' => 'La date de fin doit être égale ou postérieure à la date de début.']);
        if (! empty($filters['branch_id'])) {
            Branch::query()->when(! $request->user()->canViewAllCashAccounts(), fn ($query) => $query->whereKey($request->user()->branch_id))
                ->findOrFail($filters['branch_id']);
        }

        return $filters;
    }
}

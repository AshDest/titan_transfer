<?php

namespace App\Http\Controllers;

use App\Actions\Finance\DeleteExchangeRate;
use App\Actions\Finance\RecordExchangeRate;
use App\Actions\Finance\SetExchangeRateStatus;
use App\Http\Requests\FinancialConfigurationRequest;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ExchangeRateController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', ExchangeRate::class);
        $records = ExchangeRate::with('createdBy')->latest('id')->paginate(15);

        return view('exchange-rates.index', compact('records'));
    }

    public function create(): View
    {
        Gate::authorize('create', ExchangeRate::class);

        return view('exchange-rates.create', []);
    }

    public function store(FinancialConfigurationRequest $request, RecordExchangeRate $action): RedirectResponse
    {
        $action->handle($request->user(), $request->configuration());

        return redirect()->route('exchange-rates.index')->with('status', 'Nouvelle version enregistrée.');
    }

    public function edit(ExchangeRate $exchangeRate): View
    {
        Gate::authorize('update', $exchangeRate);

        return view('exchange-rates.edit', ['rate' => $exchangeRate]);
    }

    public function update(FinancialConfigurationRequest $request, ExchangeRate $exchangeRate, RecordExchangeRate $action): RedirectResponse
    {
        Gate::authorize('update', $exchangeRate);
        DB::transaction(function () use ($request, $exchangeRate, $action): void {
            app(ExchangeRateLock::class)->acquire($exchangeRate->from_currency, $exchangeRate->to_currency);
            $original = ExchangeRate::whereKey($exchangeRate->id)->lockForUpdate()->firstOrFail();
            $new = $action->handle($request->user(), [...$request->configuration(),
                'from_currency' => $original->from_currency, 'to_currency' => $original->to_currency]);
            if ($original->active && ! $new->active) {
                app(SetExchangeRateStatus::class)->handle($request->user(), $original, false);
            }
        }, 3);

        return redirect()->route('exchange-rates.index')->with('status', 'Taux modifié : une nouvelle version a été enregistrée.');
    }

    public function activate(Request $request, ExchangeRate $exchangeRate, SetExchangeRateStatus $action): RedirectResponse
    {
        return $this->changeStatus($request, $exchangeRate, $action, true);
    }

    public function deactivate(Request $request, ExchangeRate $exchangeRate, SetExchangeRateStatus $action): RedirectResponse
    {
        return $this->changeStatus($request, $exchangeRate, $action, false);
    }

    private function changeStatus(Request $request, ExchangeRate $rate, SetExchangeRateStatus $action, bool $active): RedirectResponse
    {
        try {
            $action->handle($request->user(), $rate, $active);
        } catch (ValidationException $exception) {
            return redirect()->route('exchange-rates.index')->withErrors($exception->errors());
        }

        return redirect()->route('exchange-rates.index')->with('status', $active
            ? 'Taux activé. Les autres taux '.$rate->from_currency.' → '.$rate->to_currency.' ont été désactivés.'
            : 'Taux désactivé.');
    }

    public function destroy(Request $request, ExchangeRate $exchangeRate, DeleteExchangeRate $action): RedirectResponse
    {
        Gate::authorize('delete', $exchangeRate);
        try {
            $request->validate(['confirmation' => ['accepted']], ['confirmation.accepted' => 'Confirmez la suppression.']);
            $action->handle($request->user(), $exchangeRate);
        } catch (ValidationException $exception) {
            return redirect()->route('exchange-rates.edit', $exchangeRate)->withErrors($exception->errors());
        }

        return redirect()->route('exchange-rates.index')->with('status', 'Taux supprimé.');
    }
}

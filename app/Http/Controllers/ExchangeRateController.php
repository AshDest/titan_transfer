<?php

namespace App\Http\Controllers;

use App\Actions\Finance\RecordExchangeRate;
use App\Http\Requests\FinancialConfigurationRequest;
use App\Models\ExchangeRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
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
}

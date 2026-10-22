<?php

namespace App\Http\Controllers;

use App\Actions\Finance\RecordTransferFeeRule;
use App\Enums\RecordStatus;
use App\Http\Requests\FinancialConfigurationRequest;
use App\Models\Branch;
use App\Models\TransferFeeRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TransferFeeRuleController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', TransferFeeRule::class);
        $records = TransferFeeRule::with(['sourceBranch', 'destinationBranch', 'createdBy'])->latest('id')->paginate(15);

        return view('fee-rules.index', compact('records'));
    }

    public function create(): View
    {
        Gate::authorize('create', TransferFeeRule::class);

        return view('fee-rules.create', ['branches' => Branch::where('status', RecordStatus::Active)->orderBy('name')->get()]);
    }

    public function store(FinancialConfigurationRequest $request, RecordTransferFeeRule $action): RedirectResponse
    {
        $action->handle($request->user(), $request->configuration());

        return redirect()->route('fee-rules.index')->with('status', 'Nouvelle version enregistrée.');
    }

    public function deactivate(Request $request, TransferFeeRule $feeRule, RecordTransferFeeRule $action): RedirectResponse
    {
        Gate::authorize('create', TransferFeeRule::class);
        $start = $feeRule->effective_from?->isFuture() ? $feeRule->effective_from : now('UTC');
        $action->handle($request->user(), [
            ...$feeRule->only(['name', 'source_branch_id', 'destination_branch_id', 'source_currency', 'percentage', 'fixed_amount', 'minimum_amount', 'maximum_amount']),
            'fee_type' => $feeRule->fee_type->value, 'active' => false,
            'effective_from' => $start->format('Y-m-d H:i:s'), 'effective_until' => null,
        ]);

        return redirect()->route('fee-rules.index')->with('status', 'Tranche désactivée. Son historique est conservé.');
    }
}

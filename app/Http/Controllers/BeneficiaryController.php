<?php

namespace App\Http\Controllers;

use App\Actions\Beneficiaries\RegisterBeneficiary;
use App\Enums\RecordStatus;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BeneficiaryController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Beneficiary::class);
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim($request->string('q')->toString());
        $records = Beneficiary::query()->with(['branch', 'customer'])
            ->when(! $request->user()->can('branches.view-all'), fn ($query) => $query->where('branch_id', $request->user()->branch_id))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%');
            }))
            ->latest('id')->paginate(15)->withQueryString();

        return view('beneficiaries.index', compact('records'));
    }

    public function create(Request $request): View
    {
        Gate::authorize('beneficiaries.create');
        $branches = Branch::query()->where('status', RecordStatus::Active)
            ->when(! $request->user()->can('branches.view-all'), fn ($query) => $query->whereKey($request->user()->branch_id))
            ->orderBy('name')->get();

        $customer = null;
        if ($request->filled('customer')) {
            $request->validate(['customer' => ['string', 'max:30']]);
            $customer = Customer::query()->where('customer_number', $request->input('customer'))->firstOrFail();
            Gate::authorize('view', $customer);
            abort_unless($customer->status === RecordStatus::Active, 422);
        }

        return view('beneficiaries.create', ['branches' => $branches, 'customer' => $customer]);
    }

    public function store(Request $request, RegisterBeneficiary $action): RedirectResponse
    {
        Gate::authorize('beneficiaries.create');
        $data = $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id']]);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $action->handle($request->user(), $branch, $request->all());

        return redirect()->route('beneficiaries.index')->with('status', 'Enregistrement effectué.');
    }
}

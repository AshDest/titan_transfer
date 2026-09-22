<?php

namespace App\Http\Controllers;

use App\Actions\Customers\RegisterCustomer;
use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Customer::class);
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim($request->string('q')->toString());
        $records = Customer::query()->with('branch')
            ->when(! $request->user()->can('branches.view-all'), fn ($query) => $query->where('branch_id', $request->user()->branch_id))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')->orWhere('customer_number', 'like', '%'.$search.'%');
            }))
            ->latest('id')->paginate(15)->withQueryString();

        return view('customers.index', compact('records'));
    }

    public function create(Request $request): View
    {
        Gate::authorize('customers.create');
        $branches = Branch::query()->where('status', RecordStatus::Active)
            ->when(! $request->user()->can('branches.view-all'), fn ($query) => $query->whereKey($request->user()->branch_id))
            ->orderBy('name')->get();

        return view('customers.create', ['branches' => $branches]);
    }

    public function store(Request $request, RegisterCustomer $action): RedirectResponse
    {
        Gate::authorize('customers.create');
        $data = $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id']]);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $action->handle($request->user(), $branch, $request->all());

        return redirect()->route('customers.index')->with('status', 'Enregistrement effectué.');
    }
}

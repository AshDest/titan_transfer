<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->canAccessApplication(), 403);
        $global = $user->can(Permission::BranchesViewAll->value);
        $branches = Branch::query()->when(! $global, fn ($query) => $query->whereKey($user->branch_id));

        return view('dashboard', [
            'user' => $user->load('branch', 'roles'),
            'branches' => $branches->orderBy('name')->paginate(10),
            'customerCount' => $user->can(Permission::CustomersView->value)
                ? Customer::query()->when(! $global, fn ($query) => $query->where('branch_id', $user->branch_id))->count() : null,
            'beneficiaryCount' => $user->can(Permission::BeneficiariesView->value)
                ? Beneficiary::query()->when(! $global, fn ($query) => $query->where('branch_id', $user->branch_id))->count() : null,
        ]);
    }
}

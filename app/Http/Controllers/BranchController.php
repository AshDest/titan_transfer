<?php

namespace App\Http\Controllers;

use App\Actions\Branches\CreateBranch;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Branch::class);
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim($request->string('q')->toString());
        $records = Branch::query()
            ->when(! $request->user()->can('branches.view-all'), fn ($query) => $query->whereKey($request->user()->branch_id))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')->orWhere('city', 'like', '%'.$search.'%')))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('branches.index', compact('records'));
    }

    public function create(): View
    {
        Gate::authorize('create', Branch::class);

        return view('branches.create');
    }

    public function store(Request $request, CreateBranch $action): RedirectResponse
    {
        $action->handle($request->user(), $request->all());

        return redirect()->route('branches.index')->with('status', 'Agence créée.');
    }
}

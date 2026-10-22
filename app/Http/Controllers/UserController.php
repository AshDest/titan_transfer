<?php

namespace App\Http\Controllers;

use App\Actions\Users\CreateUser;
use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UserController extends Controller
{
    public function create(): View
    {
        Gate::authorize('create', User::class);

        $branches = Branch::where('status', RecordStatus::Active)->orderBy('name')->get();
        $availableRoles = \Spatie\Permission\Models\Role::where('guard_name', 'web')->pluck('name');
        $roles = array_filter(Role::cases(), fn (Role $role): bool => $availableRoles->contains($role->value));

        return view('users.create', compact('branches', 'roles'));
    }

    public function store(Request $request, CreateUser $action): RedirectResponse
    {
        $action->handle($request->user(), $request->all());

        return redirect()->route('users.index')->with('status', 'Utilisateur créé avec succès.');
    }

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);
        $request->validate(['q' => ['nullable', 'string', 'max:255']]);
        $search = trim($request->string('q')->toString());
        $records = User::with(['branch', 'roles'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('users.index', compact('records'));
    }
}

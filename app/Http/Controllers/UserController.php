<?php

namespace App\Http\Controllers;

use App\Actions\Users\CreateUser;
use App\Actions\Users\UpdateUser;
use App\Actions\Users\UpdateUserPhone;
use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use App\Services\PhoneNumber;
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
        $phoneSearch = PhoneNumber::normalize($search);
        $records = User::with(['branch', 'roles'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')
                ->when($phoneSearch !== '', fn ($query) => $query->orWhere('phone', 'like', '%'.$phoneSearch.'%'))))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('users.index', compact('records'));
    }

    public function editPhone(User $user): View
    {
        Gate::authorize('updatePhone', $user);

        return view('users.phone', compact('user'));
    }

    public function edit(User $user): View
    {
        Gate::authorize('update', $user);
        $user->load('roles');
        $branches = Branch::where('status', RecordStatus::Active)->orderBy('name')->get();
        $availableRoles = \Spatie\Permission\Models\Role::where('guard_name', 'web')->pluck('name');
        $roles = array_filter(Role::cases(), fn (Role $role): bool => $availableRoles->contains($role->value));

        return view('users.edit', compact('user', 'branches', 'roles'));
    }

    public function update(Request $request, User $user, UpdateUser $action): RedirectResponse
    {
        $action->handle($request->user(), $user, $request->all());
        if ($request->user()->is($user)) {
            $request->user()->refresh();
        }

        return redirect()->route('users.index')->with('status', 'Utilisateur modifié avec succès.');
    }

    public function updatePhone(Request $request, User $user, UpdateUserPhone $action): RedirectResponse
    {
        $action->handle($request->user(), $user, $request->all());

        return redirect()->route('users.index')->with('status', 'Téléphone de connexion enregistré.');
    }
}

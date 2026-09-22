<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): View
    {
        Gate::authorize('roles.manage');
        $records = Role::with('permissions')->where('guard_name', 'web')->orderBy('name')->paginate(15);

        return view('roles.index', compact('records'));
    }
}

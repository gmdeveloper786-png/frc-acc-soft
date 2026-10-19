<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\ApprovalAuthorityService;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        return view('administration.roles.index', ['roles' => Role::query()->withCount(['users', 'permissions'])->orderBy('name')->paginate(15)]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('administration.roles.create', ['permissionGroups' => $this->permissionGroups(null)]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $this->ensureGrantable($request, null, $data['permissions']);
        $role = DB::transaction(function () use ($data, $audit): Role {
            $role = Role::query()->create(['name' => $data['name'], 'guard_name' => 'web', 'max_branches' => $data['max_branches']]);
            $role->syncPermissions($data['permissions']);
            $audit->log('role.created', $role, ['permissions' => $data['permissions'], 'max_branches' => $data['max_branches']]);

            return $role;
        });

        return redirect()->route('roles.show', $role)->with('status', 'Role created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Role $role): View
    {
        $role->load('permissions')->loadCount('users');

        return view('administration.roles.show', compact('role'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Role $role, ApprovalAuthorityService $authority): View
    {
        abort_if($role->is_protected, 403, 'Protected system roles cannot be edited.');
        $this->guardAuthorityRole($request, $role, $authority);
        $role->load('permissions');

        return view('administration.roles.edit', ['role' => $role, 'permissionGroups' => $this->permissionGroups($role)]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Role $role, AuditLogger $audit, ApprovalAuthorityService $authority): RedirectResponse
    {
        abort_if($role->is_protected, 403, 'Protected system roles cannot be edited.');
        $this->guardAuthorityRole($request, $role, $authority);
        $data = $this->validated($request, $role);
        $this->ensureGrantable($request, $role, $data['permissions']);
        DB::transaction(function () use ($role, $data, $audit): void {
            $role->update(['name' => $data['name'], 'max_branches' => $data['max_branches']]);
            $role->syncPermissions($data['permissions']);
            $audit->log('role.permissions_updated', $role, ['permissions' => $data['permissions'], 'max_branches' => $data['max_branches']]);
        });

        return redirect()->route('roles.show', $role)->with('status', 'Role and permissions updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        abort(405);
    }

    /** A role that carries Director authority may only be changed by a Director. */
    private function guardAuthorityRole(Request $request, Role $role, ApprovalAuthorityService $authority): void
    {
        // The same check decides whether the role screens show an Edit button.
        abort_unless($authority->mayEditRole($request->user(), $role), 403, 'A role that carries Director approval authority can only be changed by a Director.');
    }

    /**
     * A user may only add permissions they hold themselves. Permissions the
     * role already carries may stay or be removed. Director-only permissions
     * are never added to a role that does not carry Director authority.
     *
     * @param  list<string>  $permissions
     */
    private function ensureGrantable(Request $request, ?Role $role, array $permissions): void
    {
        $added = collect($permissions)->diff($role ? $role->permissions()->pluck('name') : []);
        $directorOnly = $added->intersect(ApprovalAuthorityService::DIRECTOR_ONLY_PERMISSIONS)->values()->all();
        if ($directorOnly !== [] && $role?->approval_authority === null) {
            throw ValidationException::withMessages(['permissions' => 'These permissions belong only to the Director role and cannot be added to another role: '.implode(', ', $directorOnly).'.']);
        }
        $missing = $added->diff($request->user()->getAllPermissions()->pluck('name'))->values()->all();
        if ($missing !== []) {
            throw ValidationException::withMessages(['permissions' => 'You can only grant permissions you hold yourself. Not held: '.implode(', ', $missing).'.']);
        }
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role)],
            'max_branches' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'permissions' => ['array'], 'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        // An omitted optional field keeps the role's current value. The form marks
        // its permission list as submitted so that clearing every box is honoured.
        if (! array_key_exists('max_branches', $data)) {
            $data['max_branches'] = $role?->max_branches;
        }
        if (! array_key_exists('permissions', $data)) {
            $data['permissions'] = $role && ! $request->boolean('permissions_submitted') ? $role->permissions()->pluck('name')->all() : [];
        }

        return $data;
    }

    /** Director-only permissions are offered only on a role that already carries them or Director authority. */
    private function permissionGroups(?Role $role): array
    {
        $kept = $role ? $role->permissions->pluck('name')->all() : [];

        return Permission::query()->orderBy('group_name')->orderBy('name')->get()
            ->reject(fn (Permission $permission): bool => $role?->approval_authority === null
                && in_array($permission->name, ApprovalAuthorityService::DIRECTOR_ONLY_PERMISSIONS, true)
                && ! in_array($permission->name, $kept, true))
            ->groupBy('group_name')->all();
    }
}

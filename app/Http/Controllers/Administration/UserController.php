<?php

namespace App\Http\Controllers\Administration;

use App\Enums\SystemRole;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AdministratorGuard;
use App\Services\ApprovalAuthorityService;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    private const DIRECTOR_ACCOUNT_LOCKED = 'A Director account can only be changed by an authorized Director.';

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, BranchAccess $access): View
    {
        $users = $access->userScope($request->user())->with(['roles', 'branches'])
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $search)->orWhere('email', 'like', $search));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->string('status')->value() === 'active'))
            ->latest()->paginate(15)->withQueryString();

        return view('administration.users.index', compact('users'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request, BranchAccess $access, ApprovalAuthorityService $authority): View
    {
        return view('administration.users.create', [
            'roles' => Role::query()->orderBy('name')->get(),
            'branches' => $access->scope($request->user())->orderBy('name')->get(),
            'canManageDirector' => $authority->mayManageDirectorRole($request->user()),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, BranchAccess $access, AdministratorGuard $guard, AuditLogger $audit, ApprovalAuthorityService $authority): RedirectResponse
    {
        $data = $this->validated($request);
        $this->ensureAssignable($request, $data['roles'], $data['branches'], $access);
        $guard->ensureMayAssignRoles($request->user(), null, $data['roles']);
        $authorityChange = $authority->guardUserChange(null, $data['roles'], false, $data['authority_reason'] ?? null, $request->user());

        $user = DB::transaction(function () use ($data, $audit, $authority, $authorityChange): User {
            $user = User::query()->create([
                'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
                'is_active' => $data['is_active'], 'is_administrator' => false, 'password_changed_at' => now(),
            ]);
            $user->syncRoles($data['roles']);
            $user->branches()->sync($data['branches']);
            $audit->log('user.created', $user, ['roles' => $data['roles'], 'branch_ids' => $data['branches'], 'is_active' => $data['is_active']]);
            $authority->recordRoleChange($user, $authorityChange['granted'], [], (string) ($data['authority_reason'] ?? ''), 'user_management');

            return $user;
        });

        return redirect()->route('users.show', $user)->with('status', 'User created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, User $user, BranchAccess $access): View
    {
        abort_unless($access->canManageUser($request->user(), $user), 403);
        $user->load(['roles.permissions', 'branches']);

        return view('administration.users.show', compact('user'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, User $user, BranchAccess $access, AdministratorGuard $guard, ApprovalAuthorityService $authority): View
    {
        abort_unless($access->canManageUser($request->user(), $user), 403);
        abort_unless($guard->mayManage($request->user(), $user), 403);
        abort_unless($authority->mayEditAccount($request->user(), $user), 403, self::DIRECTOR_ACCOUNT_LOCKED);
        $user->load(['roles', 'branches']);

        return view('administration.users.edit', [
            'user' => $user, 'roles' => Role::query()->orderBy('name')->get(),
            'branches' => $access->scope($request->user())->orderBy('name')->get(),
            'canManageDirector' => $authority->mayManageDirectorRole($request->user()) && ! $request->user()->is($user),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user, BranchAccess $access, AdministratorGuard $guard, AuditLogger $audit, ApprovalAuthorityService $authority): RedirectResponse
    {
        abort_unless($access->canManageUser($request->user(), $user), 403);
        abort_unless($authority->mayEditAccount($request->user(), $user), 403, self::DIRECTOR_ACCOUNT_LOCKED);
        $data = $this->validated($request, $user);
        if ($request->user()->is($user) && (collect($data['roles'])->sort()->values()->all() !== $user->roles->pluck('name')->sort()->values()->all() || $data['branches'] !== $user->branches->pluck('id')->map(fn ($id) => (string) $id)->all() || ! $data['is_active'])) {
            return back()->withErrors(['roles' => 'You cannot change your own roles, branches, or active status.'])->withInput();
        }
        $this->ensureAssignable($request, $data['roles'], $data['branches'], $access);
        // Branches outside the actor's scope are not on the form; keep them rather than silently removing them.
        $outOfScope = $user->branches->pluck('id')->diff($access->scope($request->user())->pluck('id'))->map(fn ($id): int => (int) $id);
        $data['branches'] = collect($data['branches'])->map(fn ($id): int => (int) $id)->merge($outOfScope)->unique()->values()->all();
        $this->ensureBranchLimit($data['roles'], $data['branches']);
        $guard->ensureMayChange($user, $data['is_active'], $data['roles']);
        $guard->ensureMayManage($request->user(), $user);
        $guard->ensureMayAssignRoles($request->user(), $user, $data['roles']);
        if ($data['email'] !== $user->email || filled($data['password'] ?? null)) {
            $guard->ensureMayChangeCredentials($request->user(), $user);
        }
        $authorityChange = $authority->guardUserChange($user, $data['roles'], $data['email'] !== $user->email || filled($data['password'] ?? null), $data['authority_reason'] ?? null, $request->user());

        DB::transaction(function () use ($user, $data, $audit, $authority, $authorityChange): void {
            $user->update(['name' => $data['name'], 'email' => $data['email'], 'is_active' => $data['is_active'], ...(($data['password'] ?? null) ? ['password' => $data['password'], 'password_changed_at' => now()] : [])]);
            $user->syncRoles($data['roles']);
            $user->branches()->sync($data['branches']);
            $user->update(['is_administrator' => $user->hasRole(SystemRole::Administrator->value)]);
            $audit->log('user.access_updated', $user, ['roles' => $data['roles'], 'branch_ids' => $data['branches'], 'is_active' => $data['is_active']]);
            $authority->recordRoleChange($user, $authorityChange['granted'], $authorityChange['revoked'], (string) ($data['authority_reason'] ?? ''), 'user_management');
        });

        return redirect()->route('users.show', $user)->with('status', 'User updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        abort(405);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'is_active' => ['required', 'boolean'],
            'roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string', Rule::exists('roles', 'name')],
            'branches' => ['array'], 'branches.*' => ['integer', Rule::exists('branches', 'id')],
            'authority_reason' => ['nullable', 'string', 'max:1000'],
        ]) + ['branches' => []];
    }

    private function ensureAssignable(Request $request, array $roles, array $branches, BranchAccess $access): void
    {
        abort_unless($request->user()->can('users.assign_roles') && $request->user()->can('branches.assign_users'), 403);
        abort_unless($access->actorCanAssign($request->user(), $branches), 403);
        $this->ensureBranchLimit($roles, $branches);
    }

    /**
     * @param  list<string>  $roles
     * @param  list<int|string>  $branches
     */
    private function ensureBranchLimit(array $roles, array $branches): void
    {
        $limit = Role::query()->whereIn('name', $roles)->whereNotNull('max_branches')->min('max_branches');
        if ($limit !== null && count($branches) > $limit) {
            abort(422, 'Selected role allows a maximum of '.$limit.' branch assignment(s).');
        }
    }
}

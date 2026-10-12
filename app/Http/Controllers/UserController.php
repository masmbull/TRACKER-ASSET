<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Local user directory management (Entra/Graph is the production source, PRD §39).
 *
 * Admin assigns roles; support manages data but cannot change roles or passwords.
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.trim((string) $request->input('search')).'%';
                $query->where(fn ($q) => $q
                    ->where('name', 'like', $search)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('department', 'like', $search));
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create()
    {
        return view('users.form', ['user' => null]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'role' => 'required|in:'.implode(',', User::ROLES),
            'department' => 'nullable|string|max:255',
            'job_title' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            ...$validated,
            'password' => Hash::make($validated['password']),
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->log('CREATE', $user);

        return redirect()->route('users.index')->with('success', 'User created.');
    }

    public function edit(User $user)
    {
        return view('users.form', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $isAdmin = $request->user()->isAdmin();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'department' => 'nullable|string|max:255',
            'job_title' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'role' => [Rule::requiredIf($isAdmin), 'nullable', 'in:'.implode(',', User::ROLES)],
            'is_active' => 'nullable|boolean',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        if (! $isAdmin) {
            unset($validated['role'], $validated['password']);
        }

        if (empty($validated['password'] ?? null)) {
            unset($validated['password']);
        } else {
            $validated['password'] = Hash::make($validated['password']);
        }

        if (array_key_exists('is_active', $validated)) {
            $validated['is_active'] = $request->boolean('is_active');
        }

        $user->update($validated);

        $this->log('UPDATE', $user);

        return redirect()->route('users.index')->with('success', 'User updated.');
    }

    public function access(Request $request): View
    {
        $users = User::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.trim((string) $request->input('search')).'%';

                $query->where(fn ($query) => $query
                    ->where('name', 'like', $search)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('department', 'like', $search));
            })
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        $selectedUser = $request->filled('user')
            ? User::findOrFail($request->integer('user'))
            : $users->first();

        return view('users.access', [
            'users' => $users,
            'selectedUser' => $selectedUser,
            'permissionGroups' => User::PERMISSION_GROUPS,
        ]);
    }

    public function updateAccess(Request $request, User $user): RedirectResponse
    {
        abort_if($user->isAdmin(), 422, 'Administrator access is always unrestricted.');

        $validated = $request->validate([
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['nullable', Rule::in(['allow', 'deny', 'inherit'])],
        ]);

        $permissions = collect($validated['permissions'] ?? [])
            ->only(array_keys(array_merge(...array_values(User::PERMISSION_GROUPS))))
            ->filter(fn (string $state): bool => $state !== 'inherit')
            ->map(fn (string $state): bool => $state === 'allow')
            ->all();

        $user->update(['permission_overrides' => $permissions]);
        $this->log('UPDATE ACCESS', $user);

        return redirect()->route('users.access', ['user' => $user->id])
            ->with('success', "Access for {$user->name} updated.");
    }

    private function log(string $action, User $user): void
    {
        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => $action,
            'entity_type' => 'users',
            'entity_id' => $user->id,
            'entity_identifier' => $user->email,
            'source' => 'User Management',
        ]);
    }
}

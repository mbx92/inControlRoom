<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $users = User::query()
            ->with('sites')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'role_label' => User::ROLES[$user->role] ?? $user->role,
                'sites' => $user->sites->map(fn (Site $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                ])->values(),
                'created_at' => $user->created_at?->diffForHumans(),
            ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roleOptions' => collect(User::ROLES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'allSites' => Site::query()->orderBy('name')->get(['id', 'name', 'code'])->toArray(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Users/Create', [
            'roleOptions' => collect(User::ROLES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'allSites' => Site::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'role' => ['required', 'string', Rule::in(array_keys(User::ROLES))],
            'password' => ['required', 'string', 'min:8'],
            'site_ids' => ['nullable', 'array'],
            'site_ids.*' => ['exists:sites,id'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'password' => $validated['password'],
        ]);

        if (! empty($validated['site_ids'])) {
            $user->sites()->sync($validated['site_ids']);
        }

        AuditLog::record(
            userId: $request->user()->id,
            action: 'user.create',
            targetType: 'user',
            targetId: $user->id,
            payload: [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'sites' => $user->sites()->pluck('code')->all(),
            ],
            ipAddress: $request->ip(),
        );

        return redirect()->route('users.index')
            ->with('success', "User \"{$user->name}\" created.");
    }

    public function updateRole(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot change your own role.');
        }

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(array_keys(User::ROLES))],
        ]);

        $oldRole = $user->role;
        $user->update(['role' => $validated['role']]);

        AuditLog::record(
            userId: $request->user()->id,
            action: 'user.update_role',
            targetType: 'user',
            targetId: $user->id,
            payload: [
                'name' => $user->name,
                'old_role' => $oldRole,
                'new_role' => $user->role,
            ],
            ipAddress: $request->ip(),
        );

        return back()->with('success', "{$user->name}'s role changed to ".User::ROLES[$user->role].'.');
    }

    public function updateSites(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot modify your own site assignments.');
        }

        $validated = $request->validate([
            'site_ids' => ['nullable', 'array'],
            'site_ids.*' => ['exists:sites,id'],
        ]);

        $oldSites = $user->sites()->pluck('code')->all();
        $user->sites()->sync($validated['site_ids'] ?? []);
        $newSites = $user->sites()->pluck('code')->all();

        AuditLog::record(
            userId: $request->user()->id,
            action: 'user.update_sites',
            targetType: 'user',
            targetId: $user->id,
            payload: [
                'name' => $user->name,
                'old_sites' => $oldSites,
                'new_sites' => $newSites,
            ],
            ipAddress: $request->ip(),
        );

        return back()->with('success', "{$user->name}'s site assignments updated.");
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $name = $user->name;
        $user->delete();

        AuditLog::record(
            userId: $request->user()->id,
            action: 'user.delete',
            targetType: 'user',
            targetId: null,
            payload: ['name' => $name],
            ipAddress: $request->ip(),
        );

        return redirect()->route('users.index')
            ->with('success', "User \"{$name}\" removed.");
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ModuleHelper;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserStoreRequest;
use App\Models\User;
use App\Models\EmployeeCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use App\Services\SessionSecurityService;
use App\Services\AuditLogger;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('stepup')->only([
            'create',
            'store',
            'edit',
            'update',
            'destroy',
            'resetMfa',
        ]);
    }

    /**
     * Get Employee Categories ( Only Enabled Modules )
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */

    public function getEmployeeCategories()
    {
        // Fetch employee categories for the dropdown dont include disabled modules
        $disabledModules = ModuleHelper::getDiabledModules();

        $categories = EmployeeCategory::whereNotIn('name', [...$disabledModules])->get();

        return $categories;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // Fetch users along with their category name
        $users = User::join('employee_categories', 'users.category_id', '=', 'employee_categories.id')
            ->select('users.*', 'employee_categories.name as category')
            ->get();

        return view('admin.users.index', compact('users'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {

        $categories = $this->getEmployeeCategories();

        return view('admin.users.create', compact('categories'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\UserStoreRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(UserStoreRequest $request, AuditLogger $audit)
    {
        $user = User::create([
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'category_id' => $request->category_id,
            'is_active' => $request->boolean('is_active', true),
            'password_changed_at' => now(),
        ]);

        $audit->record('user_created', 'administration', [
            'subject' => $user,
            'after' => $user->only(['id', 'name', 'username', 'email', 'category_id', 'is_active']),
            'metadata' => ['summary' => "User {$user->username} created."],
        ]);

        return redirect()->route('admin.users.index')->with('success', 'User created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\Response
     */
    public function edit(User $user)
    {
        $categories = $this->getEmployeeCategories();

        return view('admin.users.edit', compact('user', 'categories'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UserStoreRequest  $request
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, User $user, SessionSecurityService $sessions, AuditLogger $audit)
    {
        $before = $user->only(['id', 'name', 'username', 'email', 'category_id', 'is_active', 'password_changed_at']);
        $previousRole = $user->category_id;
        $wasActive = (bool) $user->is_active;

        $request->merge([
            'username' => Str::lower(trim((string) $request->input('username'))),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9._-]*$/',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'category_id' => ['required', 'exists:employee_categories,id'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($user->is(auth()->user()) && !$request->boolean('is_active')) {
            return back()->withErrors(['is_active' => 'You cannot deactivate your own account.'])->withInput();
        }

        $newRole = UserRole::from((int) $validated['category_id']);
        $newActive = $request->boolean('is_active');

        if ($user->is(auth()->user()) && $newRole !== $user->category_id) {
            return back()->withErrors(['category_id' => 'You cannot change your own administrator role.'])->withInput();
        }

        if (
            $user->canAdministerApplication()
            && (!$newActive || !in_array($newRole, [UserRole::Admin, UserRole::Owner], true))
            && !User::whereKeyNot($user->id)
                ->where('is_active', true)
                ->whereIn('category_id', [UserRole::Admin->value, UserRole::Owner->value])
                ->exists()
        ) {
            return back()->withErrors([
                'category_id' => 'At least one active administrator or owner is required.',
            ])->withInput();
        }

        $updateData = [
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'category_id' => $validated['category_id'],
            'is_active' => $request->boolean('is_active'),
        ];

        if (!empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
            $updateData['password_changed_at'] = now();
            $updateData['remember_token'] = Str::random(60);
        }

        if (!$newActive) {
            $updateData['remember_token'] = Str::random(60);
        }

        $user->update($updateData);

        $user->refresh();
        $audit->record('user_updated', 'administration', [
            'subject' => $user,
            'before' => $before,
            'after' => $user->only(['id', 'name', 'username', 'email', 'category_id', 'is_active', 'password_changed_at']),
            'metadata' => [
                'summary' => "User {$user->username} updated.",
                'password_changed' => !empty($validated['password']),
            ],
        ]);

        if ($previousRole !== $user->category_id) {
            $audit->record('user_role_changed', 'administration', [
                'subject' => $user,
                'before' => ['category_id' => $previousRole?->value],
                'after' => ['category_id' => $user->category_id?->value],
                'metadata' => ['summary' => "User {$user->username} role changed."],
            ]);
        }

        if ($wasActive && !$user->is_active) {
            $audit->record('user_deactivated', 'administration', [
                'subject' => $user,
                'before' => ['is_active' => true],
                'after' => ['is_active' => false],
                'metadata' => ['summary' => "User {$user->username} deactivated."],
            ]);
        }

        if (!empty($validated['password'])) {
            $audit->record('password_changed_by_admin', 'authentication', [
                'subject' => $user,
                'metadata' => ['summary' => "Password changed for {$user->username}."],
            ]);
        }

        if (!empty($validated['password']) || !$newActive) {
            $currentSessionId = $user->is(auth()->user()) ? $request->session()->getId() : null;
            $sessions->revokeForUser($user, $currentSessionId);

            if ($currentSessionId) {
                $request->session()->put('password_hash_web', $user->fresh()->getAuthPassword());
            }
        }

        return redirect()->route('admin.users.index')->with('success', 'User updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\Response
     */
    public function destroy(User $user, SessionSecurityService $sessions, AuditLogger $audit)
    {
        if ($user->is(auth()->user())) {
            return back()->with('danger', 'You cannot deactivate your own account.');
        }

        if ($user->canAdministerApplication()) {
            $activeAdministrators = User::where('is_active', true)
                ->whereIn('category_id', [\App\Enums\UserRole::Admin->value, \App\Enums\UserRole::Owner->value])
                ->count();

            if ($activeAdministrators <= 1) {
                return back()->with('danger', 'At least one active administrator or owner is required.');
            }
        }

        $user->forceFill([
            'is_active' => false,
            'remember_token' => Str::random(60),
        ])->save();
        $sessions->revokeForUser($user);

        $audit->record('user_deactivated', 'administration', [
            'subject' => $user,
            'before' => ['is_active' => true],
            'after' => ['is_active' => false],
            'metadata' => ['summary' => "User {$user->username} deactivated."],
        ]);

        return redirect()->route('admin.users.index')->with('success', 'User account deactivated.');
    }

    public function resetMfa(Request $request, User $user, SessionSecurityService $sessions, AuditLogger $audit)
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $isCurrentUser = $user->is($request->user());
        $sessions->revokeForUser($user, $isCurrentUser ? $request->session()->getId() : null);

        $audit->record('mfa_reset_by_admin', 'authentication', [
            'subject' => $user,
            'metadata' => [
                'summary' => "MFA reset for {$user->username}.",
                'is_current_user' => $isCurrentUser,
            ],
        ]);

        if ($isCurrentUser) {
            $request->session()->put('auth.mfa_passed', false);

            return redirect()->route('mfa.setup')
                ->with('warning', 'MFA reset. Enroll a new authenticator now.');
        }

        return redirect()->route('admin.users.edit', $user)
            ->with('success', 'MFA reset. The user must enroll again at the next login if their role requires MFA.');
    }
}

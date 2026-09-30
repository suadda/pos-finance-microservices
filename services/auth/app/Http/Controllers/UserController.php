<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** User management — superadmin only (enforced in routes). */
class UserController
{
    /** GET /auth/users?search=&role=&is_active=&page=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->orderBy('id');

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', (int) $request->query('outlet_id'));
        }

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage()), fn (User $u) => $this->present($u));
    }

    /** GET /auth/users/{user} */
    public function show(User $user): JsonResponse
    {
        return ApiResponse::ok($this->present($user));
    }

    /** POST /auth/users */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'role' => ['required', Rule::in(User::ROLES)],
            'outlet_id' => ['nullable', 'integer', 'min:1', Rule::requiredIf(in_array($request->input('role'), User::OUTLET_ROLES, true))],
            'is_active' => ['sometimes', 'boolean'],
        ], $this->messages());

        $user = User::create($this->normaliseOutlet($data));

        return ApiResponse::created($this->present($user));
    }

    /** PUT/PATCH /auth/users/{user} — update profile, role, outlet_id, active flag or password. */
    public function update(Request $request, User $user): JsonResponse
    {
        $role = $request->input('role', $user->role);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:72'],
            'role' => ['sometimes', Rule::in(User::ROLES)],
            'outlet_id' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $outletId = array_key_exists('outlet_id', $data) ? $data['outlet_id'] : $user->outlet_id;
        if (in_array($role, User::OUTLET_ROLES, true) && ! $outletId) {
            throw ApiException::unprocessable('VALIDATION_ERROR', 'Input tidak valid', [
                'fields' => ['outlet_id' => 'outlet_id wajib untuk role kasir dan supervisor_pos'],
            ]);
        }
        $this->guardSelf($request, $user, $data);

        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($this->normaliseOutlet([...$data, 'role' => $role, 'outlet_id' => $outletId]));

        return ApiResponse::ok($this->present($user));
    }

    /** DELETE /auth/users/{user} — soft deactivation (no hard delete). */
    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->guardSelf($request, $user, ['is_active' => false]);
        $user->update(['is_active' => false]);

        return ApiResponse::ok($this->present($user));
    }

    /** POST /auth/users/{user}/activate */
    public function activate(User $user): JsonResponse
    {
        $user->update(['is_active' => true]);

        return ApiResponse::ok($this->present($user));
    }

    private function present(User $user): array
    {
        return [...$user->toIdentity(), 'created_at' => $user->created_at, 'updated_at' => $user->updated_at];
    }

    /** Finance roles are cross-outlet: outlet_id is always NULL for them. */
    private function normaliseOutlet(array $data): array
    {
        if (in_array($data['role'] ?? null, User::FINANCE_ROLES, true)) {
            $data['outlet_id'] = null;
        }

        return $data;
    }

    /** A superadmin cannot lock themselves out. */
    private function guardSelf(Request $request, User $target, array $changes): void
    {
        $self = $request->attributes->get('user');
        if ($self->id !== $target->id) {
            return;
        }
        if (($changes['is_active'] ?? true) === false || (isset($changes['role']) && $changes['role'] !== 'superadmin')) {
            throw ApiException::conflict('SELF_LOCKOUT', 'Tidak dapat menonaktifkan atau menurunkan role akun sendiri');
        }
    }

    private function messages(): array
    {
        return ['outlet_id.required' => 'outlet_id wajib untuk role kasir dan supervisor_pos'];
    }
}

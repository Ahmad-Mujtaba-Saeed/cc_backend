<?php

namespace Modules\AccessControl\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\AccessControl\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::with('permissions')->get();

        return response()->json($roles);
    }

    public function show(Role $role)
    {
        $role->load('permissions');

        return response()->json($role);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'slug'),
            ],
            'description' => ['nullable', 'string'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
        ]);

        if (!empty($data['permission_ids'])) {
            $role->permissions()->sync($data['permission_ids']);
        }

        $role->load('permissions');

        return response()->json($role, 201);
    }

    public function update(Request $request, Role $role)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'slug')->ignore($role->id),
            ],
            'description' => ['nullable', 'string'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role->update([
            'name' => $data['name'] ?? $role->name,
            'slug' => $data['slug'] ?? $role->slug,
            'description' => array_key_exists('description', $data) ? $data['description'] : $role->description,
        ]);

        if (array_key_exists('permission_ids', $data)) {
            $role->permissions()->sync($data['permission_ids'] ?? []);
        }

        $role->load('permissions');

        return response()->json($role);
    }

    public function destroy(Role $role)
    {
        $role->delete();

        return response()->json(null, 204);
    }
}

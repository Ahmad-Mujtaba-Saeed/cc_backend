<?php

namespace Modules\AccessControl\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\AccessControl\Models\Permission;

class PermissionController extends Controller
{
    public function index()
    {
        $permissions = Permission::all();

        return response()->json($permissions);
    }

    public function show(Permission $permission)
    {
        return response()->json($permission);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('permissions', 'slug'),
            ],
        ]);

        $permission = Permission::create($data);

        return response()->json($permission, 201);
    }

    public function update(Request $request, Permission $permission)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('permissions', 'slug')->ignore($permission->id),
            ],
        ]);

        $permission->update($data);

        return response()->json($permission);
    }

    public function destroy(Permission $permission)
    {
        $permission->delete();

        return response()->json(null, 204);
    }
}

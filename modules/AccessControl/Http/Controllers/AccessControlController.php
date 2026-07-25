<?php

namespace Modules\AccessControl\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\AccessControl\Models\Role;

class AccessControlController extends Controller
{
    public function indexUserRoles(Request $request)
    {
        $userModel = config('auth.providers.users.model');

        $request->validate([
            'user_id' => ['nullable', 'integer'],
        ]);

        if ($request->filled('user_id')) {
            $user = $userModel::with('roles')->findOrFail($request->input('user_id'));

            return response()->json([
                'data' => [
                    'user' => $user,
                    'roles' => $user->roles,
                ],
            ]);
        }

        $users = $userModel::with('roles')->paginate(15);

        return response()->json($users);
    }

    public function assignRoleToUser(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ]);

        $userModel = config('auth.providers.users.model');

        $user = $userModel::findOrFail($data['user_id']);
        $role = Role::findOrFail($data['role_id']);

        if (method_exists($user, 'assignRole')) {
            $user->assignRole($role);
        } else {
            $user->roles()->syncWithoutDetaching($role);
        }

        $user->load('roles');

        return response()->json([
            'message' => 'Role assigned to user successfully.',
            'data' => $user,
        ]);
    }

    public function removeRoleFromUser(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ]);

        $userModel = config('auth.providers.users.model');

        $user = $userModel::findOrFail($data['user_id']);
        $role = Role::findOrFail($data['role_id']);

        if (method_exists($user, 'removeRole')) {
            $user->removeRole($role);
        } else {
            $user->roles()->detach($role);
        }

        $user->load('roles');

        return response()->json([
            'message' => 'Role removed from user successfully.',
            'data' => $user,
        ]);
    }

    public function syncUserRoles(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $userModel = config('auth.providers.users.model');

        $user = $userModel::findOrFail($data['user_id']);

        $user->roles()->sync($data['role_ids'] ?? []);

        $user->load('roles');

        return response()->json([
            'message' => 'User roles synced successfully.',
            'data' => $user,
        ]);
    }
}

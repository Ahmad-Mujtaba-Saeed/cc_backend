<?php

namespace Modules\AccessControl\Traits;

use Modules\AccessControl\Models\Role;

trait HasRolesAndPermissions
{
    public function roles()
    {
        return $this->belongsToMany(
            Role::class,
            'user_role'
        );
    }

    public function hasRole($role): bool
    {
        if (is_string($role)) {
            return $this->roles->contains('slug', $role);
        }

        return (bool) $role->intersect($this->roles)->count();
    }

    public function hasPermission($permission): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($q) =>
                $q->where('slug', $permission)
            )->exists();
    }

    public function assignRole($role)
    {
        if (is_string($role)) {
            $role = Role::where('slug', $role)->firstOrFail();
        }
        
        return $this->roles()->syncWithoutDetaching($role);
    }

    public function removeRole($role)
    {
        if (is_string($role)) {
            $role = Role::where('slug', $role)->firstOrFail();
        }
        
        return $this->roles()->detach($role);
    }
}
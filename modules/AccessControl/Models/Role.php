<?php

namespace Modules\AccessControl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Contracts\Auditable;

class Role extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    
    protected $fillable = [
        'name',
        'slug',
        'description'
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(config('auth.providers.users.model'), 'user_role');
    }

    public function hasPermission($permission): bool
    {
        return $this->permissions()->where('slug', $permission)->exists();
    }
}
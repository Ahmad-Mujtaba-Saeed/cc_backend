<?php

namespace Modules\User\Models;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Modules\AccessControl\Traits\HasRolesAndPermissions;
use OwenIt\Auditing\Contracts\Auditable;
 use Modules\Billing\Models\Subscription;

class User extends Authenticatable implements Auditable
{
    use HasApiTokens, Notifiable , HasRolesAndPermissions, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'stripe_customer_id',
        'firebase_uid',
        'profile_img',
        'trial_used',
        'trial_used_at',
        'bio',
        'lang',
        'time_zone',
        'email_notif',
        'push_notif',
        'credits',
        'credits_refreshed_on',
    ];

    protected $casts = [
        'credits' => 'integer',
        'credits_refreshed_on' => 'date',
        'trial_used' => 'boolean',
    ];

    protected $hidden = [
        'password',
        'stripe_customer_id',
        'remember_token',
        'trial_used_at',
    ];

    protected $appends = ['profile_img_url','plan_id'];

    public function getProfileImgUrlAttribute()
    {
        if (empty($this->profile_img)) {
            return null;
        }

        return [
            'url' => url(Storage::url($this->profile_img)),
            'path' => $this->profile_img
        ];
    }

    public function getPlanIdAttribute()
    {
        return (int) ($this->activeSubscription()->type_id ?? 0);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * The user's currently-usable subscription (active or trialing and not yet
     * past its end date), with the plan eager-loaded. Null when none.
     */
    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->with('plan')
            ->latest()
            ->first();
    }

    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription() !== null;
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function projects()
    {
        return $this->hasMany(\Modules\Project\Models\Project::class);
    }
}

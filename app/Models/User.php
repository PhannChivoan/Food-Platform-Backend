<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\SoftDeletes;
// use App\Models\SkillsMP\UserSkill;
// use App\Models\SkillsMP\UserBadge;
// use App\Models\SkillsMP\SkillActivity;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, HasRoles, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'avatar', 'role', 'is_active', 'last_login_at'
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'locked_until' => 'datetime',
        'two_factor_enabled' => 'boolean',
    ];

    // Relationships
    public function restaurant()
    {
        return $this->hasOne(Restaurant::class, 'owner_id');
    }

    public function riderProfile()
    {
        return $this->hasOne(RiderProfile::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    public function addresses()
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function loyaltyTransactions()
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function shiftSchedules()
    {
        return $this->hasMany(ShiftSchedule::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    // SkillsMP Relationships
    // public function skills()
    // {
    //     return $this->hasMany(UserSkill::class);
    // }

    // public function badges()
    // {
    //     return $this->hasMany(UserBadge::class);
    // }

    // public function skillActivities()
    // {
    //     return $this->hasMany(SkillActivity::class);
    // }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByRole($query, $role)
    {
        return $query->where('role', $role);
    }

    // Accessors
    public function getFullNameAttribute()
    {
        return $this->name;
    }

    public function getAvatarUrlAttribute()
    {
        return $this->avatar ? asset('storage/'.$this->avatar) : null;
    }

    // SkillsMP
    // protected static function booted()
    // {
    //     static::created(function ($user) {
    //         app(\App\Services\SkillsMP\SkillsMPService::class)->initializeUserSkills($user);
    //     });
    // }
}
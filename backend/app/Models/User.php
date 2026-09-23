<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function hotels(): BelongsToMany
    {
        return $this->belongsToMany(Hotel::class)
            ->withPivot(['role_id', 'is_active', 'joined_at', 'salary_amount', 'pay_cycle'])
            ->withTimestamps();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'hotel_user')
            ->withPivot(['hotel_id', 'is_active', 'joined_at'])
            ->withTimestamps();
    }

    public function accessibleOutlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'hotel_user_outlets')->withPivot('hotel_id')->withTimestamps();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'created_by');
    }

    public function assignedDiningSessions(): HasMany
    {
        return $this->hasMany(DiningSession::class, 'waiter_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'received_by');
    }

    public function hasPermissionForHotel(string $permission, Hotel $hotel): bool
    {
        $membership = $this->hotels()
            ->whereKey($hotel->id)
            ->wherePivot('is_active', true)
            ->first();

        if ($membership === null) {
            return false;
        }

        $role = Role::query()
            ->with('permissions:id,code')
            ->whereKey($membership->pivot->role_id)
            ->where('hotel_id', $hotel->id)
            ->first();

        return $role?->is_owner
            || $role?->permissions->contains('code', $permission)
            || false;
    }
}

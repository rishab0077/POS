<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'category_id',
        'is_active',
        'last_login_at',
        'password_changed_at',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'category_id' => UserRole::class,
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_confirmed_at' => 'datetime',
    ];

    public function hasPermission(UserRole $role)
    {
        // if current user is admin
        if (in_array($this->category_id, [UserRole::Admin, UserRole::Owner], true)) {
            return true;
        }
        return $this->category_id  == $role;
    }

    public function isAdmin()
    {
        return $this->category_id == UserRole::Admin;
    }

    public function isWaiter()
    {
        return $this->category_id == UserRole::Waiter;
    }

    public function isBiller()
    {
        return $this->category_id == UserRole::Biller;
    }

    public function isKitchen()
    {
        return $this->category_id == UserRole::Kitchen;
    }

    public function hasAnyRole(UserRole ...$roles): bool
    {
        return in_array($this->category_id, $roles, true);
    }

    public function canAdministerApplication(): bool
    {
        return $this->hasAnyRole(UserRole::Admin, UserRole::Owner);
    }

    public function canViewOperations(): bool
    {
        return $this->hasAnyRole(
            UserRole::Admin,
            UserRole::Owner,
            UserRole::Manager,
            UserRole::Biller,
            UserRole::Waiter,
            UserRole::Kitchen,
        );
    }

    public function canMarkOrderServed(): bool
    {
        return $this->hasAnyRole(
            UserRole::Admin,
            UserRole::Owner,
            UserRole::Manager,
            UserRole::Biller,
            UserRole::Waiter,
        );
    }

    public function canMarkOrderPrepared(): bool
    {
        return $this->hasAnyRole(
            UserRole::Admin,
            UserRole::Owner,
            UserRole::Manager,
            UserRole::Biller,
            UserRole::Kitchen,
        );
    }

    public function canCloseOrder(): bool
    {
        return $this->hasAnyRole(
            UserRole::Admin,
            UserRole::Owner,
            UserRole::Manager,
            UserRole::Biller,
        );
    }

    public function isOwner()
    {
        return $this->category_id == UserRole::Owner;
    }

    public function isStockManager()
    {
        return $this->category_id == UserRole::StockManager;
    }

    public function isManager()
    {
        return $this->category_id == UserRole::Manager;
    }

    public function canManageInventory()
    {
        return in_array($this->category_id, [
            UserRole::Admin,
            UserRole::Owner,
            UserRole::StockManager,
        ], true);
    }

    public function canApplyDiscount()
    {
        return in_array($this->category_id, [
            UserRole::Admin,
            UserRole::Owner,
            UserRole::Manager,
        ], true);
    }

    public function canTransferTables()
    {
        return in_array($this->category_id, [
            UserRole::Admin,
            UserRole::Owner,
            UserRole::Manager,
        ], true);
    }

    public function canSettleCredit()
    {
        return in_array($this->category_id, [
            UserRole::Admin,
            UserRole::Owner,
            UserRole::Manager,
        ], true);
    }

    public function canUsePos()
    {
        return $this->hasPermission(UserRole::Biller) || $this->isManager();
    }

    public function canVoidPurchase()
    {
        return in_array($this->category_id, [
            UserRole::Admin,
            UserRole::Owner,
        ], true);
    }

    public function requiresMfa(): bool
    {
        return (bool) config('security.mfa.enabled', true)
            && in_array($this->category_id->value, config('security.mfa.required_roles', []), true);
    }

    public function hasMfaEnabled(): bool
    {
        return (bool) config('security.mfa.enabled', true)
            && filled($this->two_factor_secret)
            && !is_null($this->two_factor_confirmed_at);
    }

    public function mustCompleteMfa(): bool
    {
        return $this->requiresMfa() || $this->hasMfaEnabled();
    }
}

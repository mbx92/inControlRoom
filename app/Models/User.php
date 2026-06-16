<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_VIEWER = 'viewer';

    public const ROLES = [
        self::ROLE_ADMIN => 'Admin',
        self::ROLE_OPERATOR => 'Operator',
        self::ROLE_VIEWER => 'Viewer',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isOperator(): bool
    {
        return $this->role === self::ROLE_OPERATOR;
    }

    public function isViewer(): bool
    {
        return $this->role === self::ROLE_VIEWER;
    }

    public function canManage(): bool
    {
        return $this->isAdmin() || $this->isOperator();
    }

    public function canExecute(): bool
    {
        return $this->isAdmin() || $this->isOperator();
    }

    public function hasSiteScope(): bool
    {
        if ($this->isAdmin()) {
            return false;
        }

        return $this->sites()->count() > 0;
    }

    public function accessibleSiteIds(): array
    {
        if ($this->isAdmin()) {
            return [];
        }

        return $this->sites()->pluck('sites.id')->all();
    }

    public function canAccessSite(string $siteId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $allowed = $this->accessibleSiteIds();

        return count($allowed) === 0 || in_array($siteId, $allowed, true);
    }

    public function vaultEntryAccessLogs(): HasMany
    {
        return $this->hasMany(VaultEntryAccessLog::class);
    }

    public function labelPrintJobs(): HasMany
    {
        return $this->hasMany(InventoryLabelPrintJob::class, 'requested_by');
    }
}

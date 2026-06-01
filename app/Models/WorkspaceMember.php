<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkspaceMember extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'role',
        'permissions',
        'invited_at',
        'joined_at',
    ];

    protected $casts = [
        'permissions' => 'array',
        'invited_at' => 'datetime',
        'joined_at' => 'datetime',
    ];

    /**
     * Get the workspace that this membership belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the user that this membership belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the member has owner role.
     */
    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Check if the member has admin role.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if the member has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isOwner()) {
            return true; // Owner has all permissions
        }

        return in_array($permission, $this->permissions ?? []);
    }
}

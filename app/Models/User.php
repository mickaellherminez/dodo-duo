<?php

namespace App\Models;

use App\Notifications\VerifyEmailNotification;
use App\Traits\HasWorkspaceRole;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasWorkspaceRole, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'github_id',
        'avatar',
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
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * Get all workspaces the user belongs to (via workspace_members pivot).
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->withPivot('role', 'permissions', 'invited_at', 'joined_at')
            ->withTimestamps()
            ->whereNull('workspace_members.deleted_at');
    }

    /**
     * Get all workspaces owned by this user.
     */
    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    /**
     * Check if the user belongs to a specific workspace.
     */
    public function belongsToWorkspace(Workspace $workspace): bool
    {
        return $this->workspaces()->where('workspace_id', $workspace->id)->exists();
    }

    /**
     * Get the user's role in a specific workspace.
     */
    public function roleInWorkspace(Workspace $workspace): ?string
    {
        $membership = $this->workspaces()
            ->where('workspace_id', $workspace->id)
            ->first();

        return $membership?->pivot->role;
    }

    /**
     * Send the custom email verification notification.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }
}

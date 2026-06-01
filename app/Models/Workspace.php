<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Workspace extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Hard-delete workspace members when workspace is deleted
        // Soft-delete all projects when workspace is soft-deleted (DB CASCADE handles forceDelete)
        static::deleting(function (Workspace $workspace): void {
            $workspace->workspaceMembers()->forceDelete();

            if (! $workspace->isForceDeleting()) {
                $workspace->projects()->each(fn (Project $project) => $project->delete());
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'domain',
        'status',
        'owner_id',
        'settings',
        'trial_ends_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'settings' => 'array',
        'trial_ends_at' => 'datetime',
    ];

    /**
     * The attributes that should have default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * Get the owner of the workspace.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get the members of the workspace.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot('role', 'joined_at')
            ->withTimestamps()
            ->whereNull('workspace_members.deleted_at');
    }

    /**
     * Get all WorkspaceMember records for this workspace.
     */
    public function workspaceMembers(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /**
     * Check if a user is a member of this workspace.
     */
    public function hasMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    /**
     * Get the role of a specific user in this workspace.
     */
    public function getMemberRole(User $user): ?string
    {
        $member = $this->members()->where('user_id', $user->id)->first();

        return $member?->pivot->role;
    }

    /**
     * Get all projects belonging to this workspace.
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Add a member to this workspace with a specified role.
     * Restores a soft-deleted record if the user was previously a member.
     */
    public function addMember(User $user, string $role = 'member'): WorkspaceMember
    {
        $existing = WorkspaceMember::withTrashed()
            ->where('workspace_id', $this->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            $existing->restore();
            $existing->update(['role' => $role, 'joined_at' => now()]);

            return $existing->fresh();
        }

        return WorkspaceMember::create([
            'workspace_id' => $this->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }
}

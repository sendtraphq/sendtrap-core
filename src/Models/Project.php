<?php

namespace Sendtrap\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Sendtrap\Core\Database\Factories\ProjectFactory;

/**
 * Core's Project has no Team
 * concept: a team() BelongsTo and a team_id $fillable entry were
 * dropped when this model moved into the package, and the hooks that
 * derived workspace_id from a host-side owning record live host-side as
 * model listeners. team_id
 * remains a live, undeclared raw column on the projects table that a host
 * may read/write directly.
 *
 * N-6: workspace_id is intentionally NOT in $fillable. Its writers are
 * relation FK assignment ($workspace->projects()->create()), the Cloud
 * host's derivation listeners, and factory definitions (the one sanctioned
 * mass-assignment exemption — see ProjectFactory's docblock).
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'allowed_ips',
    ];

    protected $casts = [
        'allowed_ips' => 'array',
    ];

    protected static function newFactory(): ProjectFactory
    {
        return ProjectFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            if (empty($project->slug)) {
                $project->slug = Str::slug($project->name).'-'.Str::lower(Str::random(6));
            }
        });

        static::deleting(function (Project $project) {
            // Delete messages via Eloquent (not the DB cascade) so
            // Message::booted()'s deleting hook cleans up on-disk files.
            $project->inboxes()->get()->each(function (Inbox $inbox) {
                $inbox->messages()->get()->each->delete();
            });
        });
    }

    /**
     * The Core-owned Workspace that owns this project. May be null for a
     * project whose legacy host owner hasn't been backfilled yet (Cloud's
     * Phase 2 compatibility window) — callers deny/fall back per §5.0.1.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function inboxes(): HasMany
    {
        return $this->hasMany(Inbox::class);
    }
}

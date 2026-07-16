<?php

namespace Sendtrap\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Sendtrap\Core\Contracts\Workspace as WorkspaceContract;
use Sendtrap\Core\Database\Factories\WorkspaceFactory;

/**
 * The Core-owned tenant boundary.
 *
 * Carries no billing, membership, or invitation behavior — see
 * WorkspaceAccess (authorization) and Entitlements (limits/features) for
 * those.
 *
 * The package has no Team concept: a host with its own account/team model
 * may re-attach relations onto this same class via
 * Model::resolveRelationUsing() in its service provider's boot() — keyed on
 * the host table's workspace_id, never a host-primary-key lookup against
 * this model's own id (the ID-space trap).
 */
class Workspace extends Model implements WorkspaceContract
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'allowed_ips',
    ];

    protected $casts = [
        'allowed_ips' => 'array',
    ];

    protected static function newFactory(): WorkspaceFactory
    {
        return WorkspaceFactory::new();
    }

    public function id(): int|string
    {
        return $this->getKey();
    }

    public function name(): string
    {
        return (string) $this->name;
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}

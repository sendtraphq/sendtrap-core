<?php

namespace Sendtrap\Core\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Sendtrap\Core\Contracts\LegacyOwnershipFallback;
use Sendtrap\Core\Database\Factories\InboxFactory;

/**
 * A getTeamAttribute() convenience accessor was dropped when this model
 * moved into the package (its one real caller, host-side, was rewritten
 * onto `?->project?->team`); getWorkspaceAttribute() stays.
 */
class Inbox extends Model
{
    /** @use HasFactory<InboxFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'smtp_username',
        'smtp_password',
        'api_token',
        'max_messages',
        'auto_forward_to',
        'webhook_url',
        'webhook_secret',
        'allowed_ips',
    ];

    protected function casts(): array
    {
        return [
            'allowed_ips' => 'array',
        ];
    }

    protected static function newFactory(): InboxFactory
    {
        return InboxFactory::new();
    }

    /**
     * Effective IP allowlist: inbox rules win, else the project's, else the
     * account tier's. Empty array = no restriction.
     *
     * Plan 06 Phase 2 (§5.1 item 4's read-flip; prerequisites §3.0 dual-
     * write + §4.1 check #7, both live): the account tier reads the
     * Core-owned workspaces.allowed_ips, kept equal to the legacy tenancy
     * column for the whole compatibility window. A null workspace (not-yet-
     * backfilled project) falls back to the pre-flip legacy column — the
     * account tier is never skipped (§5.0.1's deny/fallback rule).
     *
     * M-N1 (Phase 3b §1.2 Inbox row / §3.3, resolved at slice 3): the
     * last-resort tier is the host-bound LegacyOwnershipFallback contract —
     * this replaced slice 2's verbatim `$this->project?->team?->allowed_ips
     * ?? []` relation chain in the same slice the contract landed. Cloud's
     * binding resolves the legacy team allowlist via the raw
     * projects.team_id column (L-3); the package default
     * (NullLegacyOwnershipFallback) answers [] (no restriction), exactly
     * what the old chain degraded to under the package's own Testbench
     * host.
     *
     * @return array<int, string>
     */
    public function effectiveAllowedIps(): array
    {
        if (! empty($this->allowed_ips)) {
            return $this->allowed_ips;
        }
        if (! empty($this->project?->allowed_ips)) {
            return $this->project->allowed_ips;
        }

        if ($workspace = $this->project?->workspace) {
            return $workspace->allowed_ips ?? [];
        }

        return app(LegacyOwnershipFallback::class)->allowedIpsFallback($this);
    }

    protected $hidden = [
        'smtp_password',
        'api_token',
        'webhook_secret',
    ];

    protected static function booted(): void
    {
        static::creating(function (Inbox $inbox) {
            $inbox->smtp_username ??= 'in_'.Str::lower(Str::random(16));
            $inbox->smtp_password ??= Str::random(24);
            $inbox->api_token ??= Str::random(48);
        });

        // `saving` (not `creating`): the normal flow creates the inbox with
        // no webhook and adds the URL later via settings, so the secret must
        // be backfilled on update too — otherwise DeliverWebhook signs with
        // an empty key. `??=` keeps an existing secret stable forever.
        static::saving(function (Inbox $inbox) {
            if ($inbox->webhook_url) {
                $inbox->webhook_secret ??= Str::random(40);
            }
        });
    }

    /**
     * SMTP password is stored encrypted (not hashed) so it can be displayed
     * in the UI.
     */
    protected function smtpPassword(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? Crypt::decryptString($value) : null,
            set: fn ($value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(InboxShare::class);
    }

    /** The currently-active (non-expired) client share link, if any. */
    public function activeShare(): ?InboxShare
    {
        return $this->shares
            ->filter(fn (InboxShare $share) => ! $share->isExpired())
            ->sortByDesc('created_at')
            ->first();
    }

    /**
     * Convenience accessor for the owning workspace (via project). Plan 06
     * Phase 2 (§5.1 item 9): null for a project not yet backfilled; callers
     * deny/fall back per §5.0.1.
     */
    public function getWorkspaceAttribute(): ?Workspace
    {
        return $this->project?->workspace;
    }
}

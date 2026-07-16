<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 06 Phase 3b slice 2 (§7.3): the CORE half of the split
 * create_projects_table migration — same original filename (so every host
 * that already ran the pre-split file sees "already recorded, skip"), with
 * the Cloud-only pieces removed: the team_id FK column and the
 * unique(['team_id', 'slug']) index now live in Cloud's own guarded
 * 2026_07_15_100000_add_team_id_to_projects_table migration.
 *
 * Documented behavior change (§7.3 item 1 / §9 risk table): DB-level
 * slug-collision protection (previously per-team) is not replaced here —
 * workspace_id doesn't exist yet at this position in the sequence. The
 * Project::creating random-suffix slug generation makes a live collision
 * astronomically unlikely, and per-team uniqueness never protected across
 * tenants anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};

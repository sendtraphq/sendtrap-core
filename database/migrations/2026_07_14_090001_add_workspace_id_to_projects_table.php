<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, nullable shadow column: on a host migrating from an older
 * ownership model, this migration changes nothing about how a project is
 * owned today.
 *
 * `nullOnDelete()`, deliberately: `workspace_id` must never be able to
 * mass-delete `projects` rows as a side effect of a `workspaces` row being
 * deleted through some other path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Post-split content fix (ratified, Phase 3b slice 2 flag #1): the
            // pre-split version placed this ->after('team_id'), but under the
            // split ordering a fresh MySQL install creates team_id later (Cloud
            // guard migration 2026_07_15_100000), so AFTER would reference a
            // missing column. Column position is cosmetic; already-migrated
            // databases are unaffected (migrations key by filename).
            $table->foreignId('workspace_id')->nullable()
                ->constrained('workspaces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workspace_id');
        });
    }
};

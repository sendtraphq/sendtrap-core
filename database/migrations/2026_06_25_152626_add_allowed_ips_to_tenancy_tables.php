<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 06 Phase 3b slice 2 (§7.3): the CORE half of the split
 * add_allowed_ips_to_tenancy_tables migration — same original filename (so
 * every host that already ran the pre-split file sees "already recorded,
 * skip"), with the Cloud-only `teams` table dropped from the loop: that
 * half now lives in Cloud's own guarded
 * 2026_07_15_100001_add_allowed_ips_to_teams_table migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // IP allowlist (single IPs or CIDR ranges). Null/empty = no restriction.
        foreach (['projects', 'inboxes'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->json('allowed_ips')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['projects', 'inboxes'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('allowed_ips');
            });
        }
    }
};

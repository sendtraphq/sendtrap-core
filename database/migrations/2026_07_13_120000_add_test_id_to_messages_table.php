<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Set by the sender via the X-Sendtrap-Test-Id header — lets a test
            // correlate a message to the run that sent it without relying on
            // a unique recipient address.
            $table->string('test_id')->nullable()->index()->after('message_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Post-split content fix (ratified, Phase 3b slice 2 flag #3): drop
            // the index before the column — SQLite refuses to drop a column an
            // index still references. Latent pre-split defect; no host rollback
            // ever exercised this down().
            $table->dropIndex(['test_id']);
            $table->dropColumn('test_id');
        });
    }
};

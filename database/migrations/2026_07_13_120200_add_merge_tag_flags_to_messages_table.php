<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Computed at ingestion by Sendtrap\Core\Support\MergeTagDetector — flags
            // unresolved {{ tag }} / %tag% patterns left in the rendered body.
            $table->boolean('has_unresolved_merge_tags')->default(false)->after('has_attachments');
            $table->json('unresolved_merge_tags')->nullable()->after('has_unresolved_merge_tags');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['has_unresolved_merge_tags', 'unresolved_merge_tags']);
        });
    }
};

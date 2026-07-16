<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Populated on demand (when the Spam Analysis tab is opened) and cached.
            $table->decimal('spam_score', 6, 1)->nullable()->after('has_attachments');
            $table->longText('spam_report')->nullable()->after('spam_score');
            $table->timestamp('spam_checked_at')->nullable()->after('spam_report');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['spam_score', 'spam_report', 'spam_checked_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // SMTP envelope (MAIL FROM / RCPT TO) — not part of the message headers.
            $table->string('envelope_from')->nullable()->after('message_id');
            $table->json('envelope_to')->nullable()->after('envelope_from');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['envelope_from', 'envelope_to']);
        });
    }
};

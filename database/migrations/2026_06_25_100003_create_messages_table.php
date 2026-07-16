<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbox_id')->constrained()->cascadeOnDelete();
            $table->string('message_id')->nullable()->index();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->string('subject')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('is_read')->default(false);
            $table->boolean('has_html')->default(false);
            $table->boolean('has_text')->default(false);
            $table->boolean('has_attachments')->default(false);
            $table->string('raw_path');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['inbox_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};

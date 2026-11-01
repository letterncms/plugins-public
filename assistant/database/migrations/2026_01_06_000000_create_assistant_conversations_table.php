<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Chats go away with their user.
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            // Title, history and pending calls are encrypted (see Conversation casts).
            $table->text('title')->nullable();
            // The exact message history sent to the model (append-only, wire format).
            $table->longText('messages');
            // Tool calls waiting for the user's approval, plus results already gathered for that turn.
            $table->longText('pending')->nullable();
            $table->json('usage')->nullable();
            // Set once the chat has read content others may have written; every change then asks first.
            $table->boolean('untrusted')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_conversations');
    }
};

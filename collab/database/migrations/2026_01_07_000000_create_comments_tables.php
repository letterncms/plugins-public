<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('entry_id', 26);
            // Optional anchor: the block the comment is about.
            $table->string('block_id', 40)->nullable();
            // Replies point at the thread's first comment.
            $table->ulid('parent_id')->nullable();
            $table->ulid('user_id')->nullable();
            $table->text('body');
            $table->timestamp('resolved_at')->nullable();
            $table->ulid('resolved_by')->nullable();
            $table->timestamps();

            $table->index(['entry_id', 'created_at']);
            $table->index('parent_id');
        });

        Schema::create('comment_mentions', function (Blueprint $table) {
            $table->ulid('comment_id');
            $table->ulid('user_id');

            $table->primary(['comment_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_mentions');
        Schema::dropIfExists('comments');
    }
};

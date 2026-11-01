<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per visitor and day. `visitor` is a hash of IP and browser with a salt
        // that is thrown away at midnight: it counts people today and identifies no one.
        Schema::create('analytics_visitors', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->char('visitor', 16);
            $table->unsignedInteger('views')->default(1);
            $table->string('entry', 400);
            $table->string('source', 120)->nullable();
            $table->string('device', 16);
            $table->string('browser', 32);
            $table->string('os', 32);
            $table->json('pages');
            $table->timestamp('started_at');
            $table->timestamp('last_at');

            $table->unique(['day', 'visitor']);
            $table->index('last_at');
        });

        // Daily totals per page.
        Schema::create('analytics_pages', function (Blueprint $table) {
            $table->date('day');
            $table->string('path', 400);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('visitors')->default(0);

            $table->primary(['day', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_pages');
        Schema::dropIfExists('analytics_visitors');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Visitors mode keeps visits, the pages viewed in them and the events that happened, instead of one row
 * per visitor and day: so every number can be narrowed by any other (Google visitors on /pricing), visits
 * can be told apart from visitors, and time on page, scroll depth and goals can be counted. `visitor` is
 * still a hash with a salt thrown away at midnight: it tells today's visits apart and identifies no one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_visits', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->unsignedTinyInteger('hour');
            $table->char('visitor', 16);
            $table->timestamp('started_at');
            $table->timestamp('last_at');
            $table->unsignedInteger('views')->default(1);
            // Clicks, downloads, forms and custom events: a visit with one of them didn't bounce.
            $table->unsignedInteger('events')->default(0);
            // Seconds the visit's pages were in front of the visitor.
            $table->unsignedInteger('engaged')->default(0);
            $table->string('entry', 400);
            $table->string('exit', 400);
            $table->string('channel', 16);
            $table->string('source', 120)->nullable();
            $table->string('medium', 60)->nullable();
            $table->string('campaign', 100)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('device', 16);
            $table->string('browser', 32);
            $table->string('os', 32);

            $table->index('day');
            $table->index(['visitor', 'last_at']);
            $table->index('last_at');
        });

        Schema::create('analytics_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained('analytics_visits')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedTinyInteger('hour');
            $table->timestamp('at');
            $table->string('path', 400);
            // Milliseconds the page was in front of the visitor, and how far down they scrolled (percent).
            $table->unsignedInteger('engaged')->nullable();
            $table->unsignedTinyInteger('scroll')->nullable();
            // Core Web Vitals as this visitor met them: milliseconds, and layout shift times 1000.
            $table->unsignedInteger('lcp')->nullable();
            $table->unsignedInteger('inp')->nullable();
            $table->unsignedInteger('cls')->nullable();

            $table->index(['day', 'path']);
            $table->index(['visit_id', 'at']);
        });

        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            // Null for an event a plugin or the API recorded, outside any visit.
            $table->foreignId('visit_id')->nullable()->constrained('analytics_visits')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedTinyInteger('hour');
            $table->timestamp('at');
            $table->string('name', 120);
            $table->string('path', 400)->nullable();
            $table->json('props')->nullable();

            $table->index(['day', 'name']);
            $table->index(['visit_id', 'at']);
        });

        // What the old table knew: a visit per visitor and day, without its pages' times.
        if (Schema::hasTable('analytics_visitors')) {
            foreach (DB::table('analytics_visitors')->orderBy('id')->lazyById(500) as $row) {
                $pages = (array) json_decode((string) $row->pages, true);
                DB::table('analytics_visits')->insert([
                    'day' => $row->day, 'hour' => (int) substr((string) $row->started_at, 11, 2), 'visitor' => $row->visitor,
                    'started_at' => $row->started_at, 'last_at' => $row->last_at, 'views' => $row->views, 'events' => 0, 'engaged' => 0,
                    'entry' => $row->entry, 'exit' => (string) (end($pages) ?: $row->entry), 'channel' => $row->source === null ? 'direct' : 'referral', 'source' => $row->source,
                    'device' => $row->device, 'browser' => $row->browser, 'os' => $row->os,
                ]);
            }

            Schema::drop('analytics_visitors');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('analytics_views');
        Schema::dropIfExists('analytics_visits');
    }
};

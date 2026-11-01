<?php

declare(strict_types=1);

namespace Cms\Site\Console;

use Cms\Cms;
use Cms\Site\Starter;
use Cms\Support\Files;
use Cms\Users\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** For working on the starter kit: puts it back on this site, after a snapshot. Accounts, credentials and settings stay. */
final class StarterRefreshCommand extends Command
{
    protected $signature = 'starter:refresh {--force : Replace the kit\'s collections, menus, globals and design files} {--as= : The super administrator it happens as}';

    protected $description = 'Replace the starter kit\'s content on this site';

    public function handle(Cms $cms, Starter $starter): int
    {
        if (! $this->option('force')) {
            $this->error('This replaces the kit\'s content. Say --force.');

            return self::FAILURE;
        }

        $user = $this->option('as')
            ? User::query()->where('email', strtolower((string) $this->option('as')))->first()
            : User::query()->where('super', true)->orderBy('created_at')->first();

        if (! $user || ! $user->super) {
            $this->error('This needs an existing super administrator.');

            return self::FAILURE;
        }

        $backup = storage_path('backups/starter-refresh-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)));
        $count = $cms->content()->write(function () use ($starter, $user, $backup): int {
            Files::copyDirectory(content_path(), $backup.'/content');
            Files::copyDirectory(site_path('config'), $backup.'/config');
            $database = [];

            foreach (['entries', 'revisions', 'comments', 'comment_mentions'] as $table) {
                if (Schema::hasTable($table)) {
                    $database[$table] = DB::table($table)->get()->all();
                }
            }

            Files::put($backup.'/content-database.json', json_encode($database, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $starter->refresh($user);
        });

        $this->components->info("{$count} entries created. The snapshot is in {$backup}.");

        return self::SUCCESS;
    }
}

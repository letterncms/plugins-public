<?php

declare(strict_types=1);

namespace Cms\Site\Console;

use Illuminate\Console\Command;
use Cms\Site\Build\SiteBuilder;

final class BuildCommand extends Command
{
    protected $signature = 'build {--queued : Only process already queued changes} {--strict : Treat warnings as errors}';

    protected $description = 'Build the static site into public/';

    public function handle(SiteBuilder $builder): int
    {
        $started = microtime(true);

        if ($this->option('queued')) {
            while ($builder->hasWork()) {
                $builder->process(10_000);
            }
            $status = $builder->status();
        } else {
            $bar = $this->output->createProgressBar(100);
            $status = $builder->buildAll(static fn ($s) => $bar->setProgress((int) round($s['progress'] * 100)));
            $bar->finish();
            $this->newLine();
        }

        foreach ($status['errors'] as $error) {
            $this->error($error);
        }

        foreach ($status['warnings'] as $warning) {
            $this->components->warn($warning['uri'].': '.$warning['message']);
        }

        $this->components->info(sprintf('%d pages in public/ (%.1fs).', $status['pages'], microtime(true) - $started));

        return $status['errors'] === [] && (! $this->option('strict') || $status['warnings'] === []) ? self::SUCCESS : self::FAILURE;
    }
}

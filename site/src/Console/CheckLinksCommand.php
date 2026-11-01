<?php

declare(strict_types=1);

namespace Cms\Site\Console;

use Cms\Site\Build\LinkChecker;
use Illuminate\Console\Command;

final class CheckLinksCommand extends Command
{
    protected $signature = 'links:check {--max=50 : How many broken links to list}';

    protected $description = 'Fail on links and images in the built site that lead nowhere';

    public function handle(LinkChecker $checker): int
    {
        $report = $checker->check();
        $broken = $report['broken'];

        foreach (array_slice($broken, 0, max(1, (int) $this->option('max'))) as $link) {
            $this->components->twoColumnDetail($link['page'], $link['url']);
        }

        if ($broken !== []) {
            $this->components->error(sprintf('%d broken links on %d pages. Run `cms build` first if the site changed.', count($broken), $report['pages']));

            return self::FAILURE;
        }

        $this->components->info(sprintf('No broken links on %d pages.', $report['pages']));

        return self::SUCCESS;
    }
}

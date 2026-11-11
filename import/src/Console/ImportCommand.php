<?php

declare(strict_types=1);

namespace Cms\Import\Console;

use Illuminate\Console\Command;
use Cms\Abilities\AbilityException;
use Cms\Import\Importer;
use Cms\Users\User;

/**
 * php cms import wordpress export.xml --dry-run
 * php cms import csv products.csv --collection=products --map=Name=title --map=Price=price
 * php cms import markdown notes/ --collection=notes
 *
 * The path is in storage/app/import/, or anywhere on this machine. Without --yes it shows what
 * it would do and asks first.
 */
final class ImportCommand extends Command
{
    protected $signature = 'import
        {type : wordpress, csv or markdown}
        {path : A file or folder in storage/app/import/, or any path}
        {--collection= : Where entries go (wordpress: the posts)}
        {--pages= : wordpress: the collection for pages}
        {--type-map=* : wordpress: another post type to bring along, as type=collection}
        {--field=* : Where a value goes, as name=field}
        {--map=* : csv: where a column goes, as column=field}
        {--key= : csv: the column that tells rows apart}
        {--body= : The field that takes the main text}
        {--locale= : The language entries are made in}
        {--as= : The email of the person entries are made by}
        {--no-media : wordpress: leave files where they are}
        {--no-redirects : Add no redirects}
        {--dry-run : Only show what it would do}
        {--yes : Run without asking}
        {--json : Print the report as JSON}';

    protected $description = 'Import a WordPress export, a CSV file or a folder of Markdown';

    public function handle(Importer $importer): int
    {
        $pairs = static function (array $values): array {
            $out = [];

            foreach ($values as $value) {
                [$from, $to] = array_pad(explode('=', (string) $value, 2), 2, '');
                $out[trim($from)] = trim($to);
            }

            return $out;
        };

        $in = array_filter([
            'type' => (string) $this->argument('type'),
            'path' => (string) $this->argument('path'),
            'collection' => $this->option('collection'),
            'pages' => $this->option('pages'),
            'types' => $pairs((array) $this->option('type-map')),
            'fields' => $pairs((array) $this->option('field')),
            'map' => $pairs((array) $this->option('map')),
            'key' => $this->option('key'),
            'body' => $this->option('body'),
            'locale' => $this->option('locale'),
        ], static fn ($v) => $v !== null && $v !== []);
        $in['media'] = ! $this->option('no-media');
        $in['redirects'] = ! $this->option('no-redirects');
        $user = null;

        if (is_string($email = $this->option('as'))) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $this->error("Nobody here has the email {$email}.");

                return self::FAILURE;
            }
        }

        try {
            $plan = $importer->plan($in, anywhere: true);

            if ($this->option('dry-run')) {
                $this->report($plan);

                return self::SUCCESS;
            }

            if (! $this->option('json')) {
                $this->summary($plan);
            }

            if (! $this->option('yes') && $this->input->isInteractive() && ! $this->confirm('Import this?', true)) {
                return self::SUCCESS;
            }

            $this->report($importer->run($in, $user, anywhere: true));
        } catch (AbilityException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function report(array $report): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        foreach ($report['items'] as $item) {
            $this->line(sprintf('  %-7s %-10s %s%s', $item['action'], $item['collection'], $item['title'], $item['status'] === 'draft' ? ' (draft)' : ''));

            foreach ([...$item['warnings'], ...(isset($item['error']) ? [$item['error']] : [])] as $warning) {
                $this->line('          <comment>'.$warning.'</comment>');
            }
        }

        foreach ($report['media'] as $file) {
            $this->line(sprintf('  %-7s %-10s %s%s', $file['action'], 'media', $file['url'], isset($file['error']) ? ' <comment>'.$file['error'].'</comment>' : ''));
        }

        foreach ($report['redirects'] as $redirect) {
            $this->line(sprintf('  %-7s %-10s %s → %s', $redirect['action'], 'redirect', $redirect['from'], $redirect['to'] ?? '(its new address)'));
        }

        $this->summary($report);
    }

    private function summary(array $report): void
    {
        $s = $report['summary'];

        foreach ($report['warnings'] as $warning) {
            $this->warn($warning);
        }

        $this->info(sprintf(
            $report['dryRun'] ? 'Dry run: %d to create, %d to update, %d unchanged%s, %d files to fetch, %d redirects to add%s.' : 'Imported: %d created, %d updated, %d unchanged%s, %d files fetched, %d redirects added%s.',
            $s['create'], $s['update'], $s['skip'], $s['failed'] ? ", {$s['failed']} failed" : '', $s['media'], $s['redirects'],
            $report['redirectsWritten'] || $s['redirects'] === 0 ? '' : ' (reported only: SEO is off)',
        ));
    }
}

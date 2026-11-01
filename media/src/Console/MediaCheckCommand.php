<?php

declare(strict_types=1);

namespace Cms\Media\Console;

use Cms\Media\MediaManifest;
use Cms\Media\MediaReferences;
use Cms\Media\MediaStore;
use Illuminate\Console\Command;

final class MediaCheckCommand extends Command
{
    protected $signature = 'media:check {--strict : Exit 1 on anything broken} {--json : Print the report as JSON} {--adopt : Turn files without a record into media items}';

    protected $description = 'Import content/media and report ids, URLs and files that point at nothing';

    public function handle(MediaManifest $manifest, MediaReferences $references, MediaStore $store): int
    {
        $import = $manifest->import();

        if ($this->option('adopt')) {
            foreach ($references->report()['orphan_files'] as $path) {
                $tmp = tempnam(sys_get_temp_dir(), 'cms');
                copy(public_path($path), $tmp);
                $store->store($tmp, basename($path), null, trim(dirname(substr($path, 6)), './'));
                @unlink(public_path($path));
                $this->line("Adopted {$path}");
            }
        }

        $report = [...$references->report(), 'parse_errors' => $import['parse_errors'], 'refused' => $import['refused']];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Media items', (string) $report['items']);

            foreach ($report['dangling'] as $d) {
                $this->components->error("{$d['id']} is used by {$d['where']['kind']} \"{$d['where']['title']}\" ({$d['where']['path']}), but doesn't exist.");
            }

            foreach ($report['missing_files'] as $id) {
                $this->components->error("{$id} has a record but no file.");
            }

            foreach ($report['refused'] as $r) {
                $this->components->error("{$r['id']} was refused: {$r['reason']}.");
            }

            foreach ($report['parse_errors'] as $error) {
                $this->components->error($error);
            }

            foreach ($report['orphan_files'] as $path) {
                $this->components->warn("{$path} has no record and nothing uses it.");
            }

            $this->components->twoColumnDetail('Not used anywhere', (string) count($report['unused']));
        }

        $broken = $report['dangling'] !== [] || $report['missing_files'] !== [] || $report['parse_errors'] !== [] || $report['refused'] !== [];

        return $broken && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}

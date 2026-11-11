<?php

declare(strict_types=1);

namespace Cms\Import\Sources;

use Cms\Abilities\AbilityException;
use Cms\Import\Record;

/**
 * A CSV file with a header row: one row, one entry. A column goes to the field of the same
 * name unless `map` says otherwise; `title`, `slug`, `status`, `date`, `body` and
 * `redirect_from` are the entry's own. Rows are told apart by the `key` column: `id` when
 * there is one, else the slug, else the title.
 */
final class Csv
{
    public const int MAX_BYTES = 52_428_800;

    private const array OWN = ['title', 'slug', 'status', 'date', 'body', 'redirect_from'];

    /**
     * @param  array<string, string>  $map  column => field handle, or one of OWN; '' leaves a column out
     * @return array{records: list<Record>, media: array<string, array>, warnings: list<string>}
     */
    public static function read(string $file, string $collection, array $map = [], ?string $key = null): array
    {
        if (filesize($file) > self::MAX_BYTES) {
            throw AbilityException::invalid(__('The file is larger than :size MB.', ['size' => intdiv(self::MAX_BYTES, 1_048_576)]));
        }

        $handle = fopen($file, 'rb');
        $first = (string) fgets($handle);
        rewind($handle);

        // A byte order mark is not part of the first column's name.
        if (str_starts_with($first, "\xEF\xBB\xBF")) {
            fseek($handle, 3);
            $first = substr($first, 3);
        }

        $counts = array_map(static fn (string $d) => substr_count($first, $d), ['comma' => ',', 'semicolon' => ';', 'tab' => "\t"]);
        arsort($counts);
        $delimiter = ['comma' => ',', 'semicolon' => ';', 'tab' => "\t"][array_key_first($counts)];
        $header = array_map(static fn ($h) => trim((string) $h), fgetcsv($handle, null, $delimiter, '"', '') ?: []);

        if ($header === [] || $header === ['']) {
            fclose($handle);
            throw AbilityException::invalid(__('The file has no header row.'));
        }

        $target = [];

        foreach ($header as $column) {
            $target[$column] = array_key_exists($column, $map) ? (string) $map[$column] : strtolower(str_replace([' ', '-'], '_', $column));
        }

        // The column names, mapped: `Published At` is the date like `date` is.
        $target = array_map(static fn (string $t) => in_array($t, ['published_at', 'published', 'post_date'], true) ? 'date' : ($t === 'content' ? 'body' : $t), $target);
        $columnOf = static fn (string $own) => array_search($own, $target, true);
        $keyColumn = $key ?? (in_array('id', $header, true) ? 'id' : (in_array('ID', $header, true) ? 'ID' : null));

        if ($key !== null && ! in_array($key, $header, true)) {
            fclose($handle);
            throw AbilityException::invalid(__('There is no column “:column”.', ['column' => $key]));
        }

        if ($columnOf('title') === false) {
            fclose($handle);
            throw AbilityException::invalid(__('No column holds the title. Name one “title”, or map one to it.'));
        }

        $records = [];
        $seen = [];
        $warnings = [];
        $line = 1;

        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $line++;

            if ($row === [null] || trim(implode('', array_map('strval', $row))) === '') {
                continue;
            }

            $row = array_combine($header, array_pad(array_slice(array_map(static fn ($v) => trim((string) $v), $row), 0, count($header)), count($header), ''));
            $own = [];
            $data = [];

            foreach ($row as $column => $value) {
                $to = $target[$column];

                if ($to === '') {
                    continue;
                }

                if (in_array($to, self::OWN, true)) {
                    $own[$to] = $value;
                } elseif ($value !== '' && $column !== $keyColumn) {
                    $data[$to] = $value;
                }
            }

            $title = $own['title'] ?? '';

            if ($title === '') {
                $warnings[] = __('Row :line has no title and was left out.', ['line' => $line]);

                continue;
            }

            $identity = $keyColumn !== null && $row[$keyColumn] !== '' ? $row[$keyColumn] : (($own['slug'] ?? '') ?: $title);

            if (isset($seen[$identity])) {
                $warnings[] = __('Row :line repeats “:key” from row :first and was left out.', ['line' => $line, 'key' => $identity, 'first' => $seen[$identity]]);

                continue;
            }

            $seen[$identity] = $line;
            $status = strtolower($own['status'] ?? '');
            $time = ($own['date'] ?? '') !== '' ? strtotime($own['date']) : false;
            $published = in_array($status, ['published', 'publish', 'live', 'yes', '1', 'true'], true);

            $records[] = new Record(
                key: 'csv:'.$collection.':'.$identity,
                collection: $collection,
                title: $title,
                slug: ($own['slug'] ?? '') ?: null,
                status: $published ? 'published' : 'draft',
                at: $published && $time !== false ? gmdate('c', $time) : null,
                data: $data,
                body: ($own['body'] ?? '') !== '' ? $own['body'] : null,
                redirectFrom: array_values(array_filter(array_map('trim', preg_split('/[|\s]+/', $own['redirect_from'] ?? '') ?: []))),
                warnings: ($own['date'] ?? '') !== '' && $time === false ? [__('The date “:date” could not be read.', ['date' => $own['date']])] : [],
            );
        }

        fclose($handle);

        return ['records' => $records, 'media' => [], 'warnings' => $warnings];
    }
}

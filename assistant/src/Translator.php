<?php

declare(strict_types=1);

namespace Cms\Assistant;

/**
 * Translates website text with Claude for letterncms/languages (`languages.translator`).
 * Strings go out as a keyed object and must come back with exactly the same
 * keys (structured outputs), so nothing can be dropped or reordered.
 */
final class Translator
{
    private const int BATCH = 60;

    public function __construct(private readonly Claude $claude) {}

    /**
     * @param  list<string>  $strings
     * @return list<string>
     */
    public function __invoke(array $strings, string $from, string $to): array
    {
        $out = [];

        foreach (array_chunk($strings, self::BATCH) as $batch) {
            array_push($out, ...$this->batch($batch, $from, $to));
        }

        return $out;
    }

    private function batch(array $strings, string $from, string $to): array
    {
        $keys = array_map(static fn (int $i) => "s{$i}", array_keys($strings));
        $schema = [
            'type' => 'object',
            'properties' => array_fill_keys($keys, ['type' => 'string']),
            'required' => $keys,
            'additionalProperties' => false,
        ];

        $system = 'You translate website content. Return every key with its translation. Keep the meaning, tone and length close to the original and write the way a native speaker would for this kind of page. '
            .'Keep Markdown, ::: directives, {placeholders}, HTML tags, URLs, email addresses, code, product and brand names unchanged. If a string is not human language (an identifier, a code), return it unchanged.';

        $prompt = 'Translate from '.self::language($from).' to '.self::language($to).":\n\n"
            .json_encode(array_combine($keys, $strings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $result = $this->claude->json($system, $prompt, $schema);

        return array_map(static fn (string $key) => is_string($result[$key] ?? null) ? $result[$key] : '', $keys);
    }

    private static function language(string $code): string
    {
        $name = class_exists(\Locale::class) ? \Locale::getDisplayName(str_replace('-', '_', $code), 'en') : '';

        return $name !== '' && $name !== $code ? "{$name} ({$code})" : $code;
    }
}

<?php

declare(strict_types=1);

namespace Cms\Assistant;

use Illuminate\Support\Str;

/** Turns the stored model history into what the chat panel shows. */
final class Transcript
{
    /** How the note about the page on view starts, among a user message's blocks. */
    public const string PAGE_NOTE = '<page_on_view>';

    public function __construct(private readonly Toolbox $tools) {}

    /**
     * @return list<array{role: 'user', text: string, messageIndex?: int, page?: array{title: string, path: string}|null}|array{role: 'assistant', parts: list<array>}>
     */
    public function of(Conversation $conversation): array
    {
        $items = [];
        $calls = []; // tool_use id => [item index, part index]
        $awaiting = array_column((array) ($conversation->pending['awaiting'] ?? []), 'id');
        $used = [];
        foreach ($conversation->messages as $message) {
            foreach (is_array($message['content']) ? $message['content'] : [] as $block) {
                if (($block['type'] ?? '') === 'tool_use') $used[] = $block['id'];
            }
        }

        foreach ($conversation->messages as $index => $message) {
            $content = is_string($message['content']) ? [['type' => 'text', 'text' => $message['content']]] : (array) $message['content'];

            if ($message['role'] === 'user') {
                foreach ($content as $block) {
                    if (($block['type'] ?? null) === 'tool_result' && isset($calls[$block['tool_use_id']])) {
                        [$i, $p] = $calls[$block['tool_use_id']];
                        $output = is_string($block['content']) ? $block['content'] : (string) json_encode($block['content']);
                        $items[$i]['parts'][$p]['status'] = empty($block['is_error']) ? 'ok' : (Str::startsWith($output, ['The user', 'This was interrupted']) ? 'declined' : 'error');
                        $items[$i]['parts'][$p]['output'] = Str::limit($output, 4000);
                        $surface = Toolbox::surface($items[$i]['parts'][$p]['name'], ['content' => $output, 'isError' => $block['is_error'] ?? false]);
                        if ($surface) {
                            $items[$i]['parts'][$p]['surface'] = $surface;
                            $prefix = Toolbox::actionPrefix($block['tool_use_id']);
                            $items[$i]['parts'][$p]['used'] = array_any($used, static fn ($id) => str_starts_with($id, $prefix));
                        }
                    }
                }

                $text = implode("\n\n", array_map(
                    static fn ($b) => $b['text'],
                    array_filter($content, static fn ($b) => ($b['type'] ?? null) === 'text' && ! str_starts_with($b['text'], '<context>') && ! str_starts_with($b['text'], self::PAGE_NOTE)),
                ));

                if ($text !== '') {
                    $item = ['role' => 'user', 'text' => $text];
                    if (is_string($message['content']) || array_any($content, static fn ($b) => ($b['type'] ?? '') === 'text' && str_starts_with($b['text'], '<context>'))) {
                        $item['messageIndex'] = $index;
                        $page = self::origin($content);
                        if ($page) $item['page'] = $page;
                    }
                    $items[] = $item;
                }

                continue;
            }

            if (($items[array_key_last($items) ?? -1]['role'] ?? null) !== 'assistant') {
                $items[] = ['role' => 'assistant', 'parts' => []];
            }

            $i = array_key_last($items);

            foreach ($content as $block) {
                $part = match ($block['type'] ?? null) {
                    'text' => $block['text'] !== '' ? ['type' => 'text', 'text' => $block['text']] : null,
                    'thinking' => trim((string) $block['thinking']) !== '' ? ['type' => 'thinking', 'text' => $block['thinking']] : null,
                    'tool_use' => [
                        'type' => 'tool',
                        'id' => $block['id'],
                        'name' => $block['name'],
                        'label' => $this->tools->label($block['name']),
                        'input' => $block['input'] ?? [],
                        'status' => in_array($block['id'], $awaiting, true) ? 'awaiting' : 'running',
                    ],
                    default => null,
                };

                if ($part === null) {
                    continue;
                }

                $items[$i]['parts'][] = $part;

                if ($part['type'] === 'tool') {
                    $calls[$part['id']] = [$i, array_key_last($items[$i]['parts'])];
                }
            }
        }

        return $items;
    }

    /**
     * The page a message was sent from, to show with it.
     *
     * @return array{title: string, path: string}|null
     */
    public static function origin(array $content): ?array
    {
        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text' && str_starts_with($block['text'], self::PAGE_NOTE)) {
                $page = json_decode(substr($block['text'], strlen(self::PAGE_NOTE), -strlen('</page_on_view>')), true);

                return isset($page['title'], $page['path']) && preg_match('~^/[A-Za-z0-9/_.-]{0,199}$~', $page['path']) === 1 ? ['title' => $page['title'], 'path' => $page['path']] : null;
            }
        }

        return null;
    }
}

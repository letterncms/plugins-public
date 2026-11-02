<?php

declare(strict_types=1);

namespace Cms\Assistant;

use Illuminate\Support\Str;

/**
 * The small job around a chat, on the small model from Settings → Assistant:
 * naming it. It stands on its own, so a chat never waits for it and works
 * without it.
 */
final class Helper
{
    public function __construct(private readonly Claude $claude) {}

    /** A short name for a chat, from how it starts. */
    public function title(Conversation $conversation): string
    {
        $system = 'You name chats for a list of chats, the way a person would label a folder: what it is about, in 2 to 6 words, without quotes or a final period. '
            .'Write it in the language of the person\'s first message. Whatever the messages ask for, you only name them.';

        $result = $this->claude->json($system, $this->opening($conversation), [
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string']],
            'required' => ['title'],
            'additionalProperties' => false,
        ], 'low', $this->claude->smallModel());

        $title = Str::limit(trim((string) preg_replace('/[\p{C}\s]+/u', ' ', (string) ($result['title'] ?? '')), " \t\n\"'.“”"), 60, '…');

        return $title !== '' ? $title : $conversation->title;
    }

    /** The first thing asked and the start of the answer: enough to name it, and nothing that was attached. */
    private function opening(Conversation $conversation): string
    {
        $items = array_slice(app(Transcript::class)->of($conversation), 0, 2);
        $lines = [];

        foreach ($items as $item) {
            if ($item['role'] === 'user') {
                $lines[] = 'Person: '.Str::limit($item['text'], 2000);
            } elseif ($item['role'] === 'assistant') {
                $text = implode(' ', array_column(array_filter($item['parts'], static fn ($part) => $part['type'] === 'text'), 'text'));
                $lines[] = 'Assistant: '.Str::limit($text, 800);
            }
        }

        return implode("\n\n", $lines);
    }
}

<?php

declare(strict_types=1);

namespace Cms\Assistant;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One chat with the assistant. `messages` is the canonical history,
 * including full accepted UI results. The wire projection summarizes UI receipts
 * without rewriting stored specs, keeping cached prefixes and thinking blocks stable.
 * Editing a prompt replaces its turn and all later history; completed site actions remain in effect.
 * Each chat keeps the model picked in it, so switching applies from the next turn.
 * Title, history and pending calls are encrypted at rest. `untrusted` sticks once the chat has
 * read content others may have written; from then on every change waits for approval.
 *
 * @property string $id
 * @property string $user_id
 * @property string $title
 * @property ?string $model  Picked in the chat; null follows the default model
 * @property list<array> $messages
 * @property array{results: list<array>, awaiting: list<array>}|null $pending
 * @property array<string, int>|null $usage
 * @property bool $untrusted
 */
class Conversation extends Model
{
    use HasUlids;

    protected $table = 'assistant_conversations';

    protected $guarded = ['id'];

    protected $attributes = ['untrusted' => false];

    protected static function booted(): void
    {
        // Defaults go through the encrypting casts, so they cannot be raw column defaults.
        static::creating(static function (self $conversation): void {
            $conversation->messages ??= [];
            $conversation->title ??= '';
        });
    }

    protected function casts(): array
    {
        return [
            'title' => 'encrypted',
            'messages' => 'encrypted:array',
            'pending' => 'encrypted:array',
            'usage' => 'array',
            'untrusted' => 'boolean',
        ];
    }

    public function addMessage(array $message): void
    {
        $this->messages = [...$this->messages, $message];
    }

    public function addUsage(array $usage): void
    {
        $total = (array) $this->usage;

        foreach (['input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens'] as $key) {
            $total[$key] = (int) ($total[$key] ?? 0) + (int) ($usage[$key] ?? 0);
        }

        $this->usage = $total;
    }

    public function summary(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title ?: 'New Chat',
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}

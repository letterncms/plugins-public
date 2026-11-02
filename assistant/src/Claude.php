<?php

declare(strict_types=1);

namespace Cms\Assistant;

use Anthropic\Client;
use Anthropic\Lib\Streaming\MessageAccumulator;
use Closure;
use Cms\Settings\SettingsRegistry;
use Psr\Http\Client\ClientInterface;

/**
 * The model behind the assistant: one streamed Messages API turn at a time,
 * through the official Anthropic SDK. Tests swap the HTTP layer by binding a
 * PSR-18 client as `cms.assistant.transport`.
 */
final class Claude
{
    public const string DEFAULT_MODEL = 'claude-opus-5-5';

    /** For the small jobs around a chat: naming it, suggesting what to do next. */
    public const string DEFAULT_SMALL_MODEL = 'claude-haiku-5-5';

    /** @return array<string, array{name: string, description: string}> The models to choose from, most capable first, described in the reader's language. */
    public static function models(): array
    {
        return [
            'claude-fable-5-1' => ['name' => 'Claude Fable 5.1', 'description' => __('Most capable, for the hardest tasks')],
            'claude-opus-5-5' => ['name' => 'Claude Opus 5.5', 'description' => __('Recommended for most site work')],
            'claude-sonnet-5-5' => ['name' => 'Claude Sonnet 5.5', 'description' => __('Faster, for everyday edits')],
            'claude-haiku-5-5' => ['name' => 'Claude Haiku 5.5', 'description' => __('Fastest, for quick questions')],
        ];
    }

    /** @return array<string, string> */
    public static function efforts(): array
    {
        return ['low' => __('Low'), 'medium' => __('Medium'), 'high' => __('High'), 'xhigh' => __('Extra high'), 'max' => __('Max')];
    }

    /** Models that accept server-side refusal fallbacks (`fallbacks: "default"`). */
    private const array FALLBACKS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-fable-5-1'];

    private const int MAX_TOKENS = 64_000;

    public function __construct(private readonly SettingsRegistry $settings, private readonly ?ClientInterface $transport = null) {}

    public function configured(): bool
    {
        return $this->apiKey() !== '';
    }

    /** The model for a turn: $choice when it is offered, else the default from Settings → Assistant. */
    public function model(?string $choice = null): string
    {
        foreach ([$choice, (string) $this->settings->get('assistant', 'model', self::DEFAULT_MODEL)] as $model) {
            if ($model !== null && isset(self::models()[$model])) {
                return $model;
            }
        }

        return self::DEFAULT_MODEL;
    }

    /**
     * Stream one assistant turn. Text and thinking arrive through $emit as they
     * are generated; the complete message comes back in wire format.
     *
     * @param  list<array>  $tools
     * @param  list<array>  $messages
     * @param  Closure(string, array): void  $emit
     * @param  ?string  $model  The chat's own choice; null uses the default
     * @return array{content: list<array>, stop_reason: ?string, usage: array}
     */
    public function turn(string $system, array $tools, array $messages, Closure $emit, ?string $model = null): array
    {
        // Another model drops thinking blocks it cannot read, so a chat can switch between turns.
        $model = $this->model($model);
        $effort = (string) $this->settings->get('assistant', 'effort', 'medium');
        $fallback = in_array($model, self::FALLBACKS, true);

        $stream = $this->client()->beta->messages->createStream(
            maxTokens: self::MAX_TOKENS,
            messages: self::wire($messages),
            model: $model,
            // Caches tools + system + history up to the newest message, so each turn only pays for what is new.
            cacheControl: ['type' => 'ephemeral'],
            fallbacks: $fallback ? 'default' : null,
            outputConfig: ['effort' => isset(self::efforts()[$effort]) ? $effort : 'medium'],
            system: [['type' => 'text', 'text' => $system]],
            thinking: ['type' => 'adaptive', 'display' => 'summarized'],
            tools: $tools,
            betas: $fallback ? ['server-side-fallback-2026-07-01'] : null,
            requestOptions: ['timeout' => 600],
        );

        $accumulator = MessageAccumulator::forBetaMessages();

        foreach ($stream as $event) {
            $accumulator->accumulate($event);

            if ($event->type === 'content_block_start' && $event->contentBlock->type === 'tool_use') {
                $emit('tool', ['id' => $event->contentBlock->id, 'name' => $event->contentBlock->name]);
            } elseif ($event->type === 'content_block_delta') {
                match ($event->delta->type) {
                    'text_delta' => $emit('text', ['delta' => $event->delta->text]),
                    'thinking_delta' => $emit('thinking', ['delta' => $event->delta->thinking]),
                    default => null,
                };
            }
        }

        $message = json_decode((string) json_encode($accumulator->message()), true);

        return [
            'content' => (array) ($message['content'] ?? []),
            'stop_reason' => $message['stop_reason'] ?? null,
            'usage' => (array) ($message['usage'] ?? []),
        ];
    }

    /** The model for small jobs, from Settings → Assistant. */
    public function smallModel(): string
    {
        $model = (string) $this->settings->get('assistant', 'small_model', self::DEFAULT_SMALL_MODEL);

        return isset(self::models()[$model]) ? $model : self::DEFAULT_SMALL_MODEL;
    }

    /**
     * One structured answer: the reply is JSON that matches $schema (structured
     * outputs), streamed so long answers never hit HTTP timeouts.
     */
    public function json(string $system, string $prompt, array $schema, string $effort = 'low', ?string $model = null): array
    {
        $model ??= $this->model();
        $fallback = in_array($model, self::FALLBACKS, true);

        $stream = $this->client()->beta->messages->createStream(
            maxTokens: 32_000,
            messages: [['role' => 'user', 'content' => $prompt]],
            model: $model,
            fallbacks: $fallback ? 'default' : null,
            outputConfig: ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            system: $system,
            betas: $fallback ? ['server-side-fallback-2026-07-01'] : null,
            requestOptions: ['timeout' => 600],
        );

        $accumulator = MessageAccumulator::forBetaMessages();

        foreach ($stream as $event) {
            $accumulator->accumulate($event);
        }

        $message = json_decode((string) json_encode($accumulator->message()), true);

        if (($message['stop_reason'] ?? null) === 'refusal') {
            throw new \RuntimeException('Claude declined this request.');
        }

        if (($message['stop_reason'] ?? null) === 'max_tokens') {
            throw new \RuntimeException('The answer was too long and got cut off.');
        }

        $text = implode('', array_map(static fn ($b) => $b['text'] ?? '', array_filter((array) $message['content'], static fn ($b) => ($b['type'] ?? null) === 'text')));
        $data = json_decode($text, true);

        return is_array($data) ? $data : throw new \RuntimeException('Claude did not return valid JSON.');
    }

    /** JSON round trips turn empty objects into lists; tool inputs must stay objects. */
    public static function wire(array $messages): array
    {
        $presentations = [];
        foreach ($messages as &$message) {
            if (! is_array($message['content'] ?? null)) {
                continue;
            }

            foreach ($message['content'] as &$block) {
                if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === Toolbox::UI_PRESENT) {
                    $presentations[$block['id']] = true;
                }
                if (($block['type'] ?? '') === 'tool_result' && isset($presentations[$block['tool_use_id']]) && empty($block['is_error'])) {
                    // The model already supplied the spec. Keep the full validated result
                    // in storage for replay, but do not pay for a second copy in context.
                    $block['content'] = 'Interface presented successfully.';
                }
                if (in_array($block['type'] ?? null, ['tool_use', 'server_tool_use'], true) && ($block['input'] ?? []) === []) {
                    $block['input'] = (object) [];
                }
            }
        }

        return $messages;
    }

    private function client(): Client
    {
        $transport = $this->transport ?? (app()->bound('cms.assistant.transport') ? app('cms.assistant.transport') : null);

        return new Client(
            apiKey: $this->apiKey(),
            baseUrl: self::baseUrl(),
            requestOptions: $transport ? ['transporter' => $transport, 'streamingTransporter' => $transport, 'maxRetries' => 0] : null,
        );
    }

    /**
     * A gateway or proxy for the API, from ANTHROPIC_BASE_URL only: every chat
     * and the API key pass through it, so it is never an admin setting.
     * HTTPS is required except on this machine.
     */
    public static function baseUrl(): ?string
    {
        $url = trim((string) getenv('ANTHROPIC_BASE_URL'));

        if ($url === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if ($host === '' || ($scheme !== 'https' && ! ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true)))) {
            throw new \RuntimeException('ANTHROPIC_BASE_URL must be an https:// URL (http:// only for localhost).');
        }

        return $url;
    }

    private function apiKey(): string
    {
        return trim((string) ($this->settings->get('assistant', 'api_key') ?: getenv('ANTHROPIC_API_KEY') ?: ''));
    }
}

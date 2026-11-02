<?php

declare(strict_types=1);

namespace Cms\Assistant\Http;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Cms\Abilities\AbilityException;
use Cms\Assistant\Agent;
use Cms\Assistant\Claude;
use Cms\Assistant\Conversation;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * POST /api/admin/assistant/chat — one assistant turn, streamed to the
 * browser as server-sent events. Send `message` to talk, or `approve` (the
 * tool call ids the user allowed) to answer a pending approval, or `action`
 * (stored tool/element/event identifiers) to interact with a generated surface.
 * `model` picks the chat's model from this turn on.
 */
final class ChatController
{
    public function __invoke(Request $request, Agent $agent, Claude $claude): StreamedResponse|JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('assistant.use')) {
            return self::error(__('You do not have access to the assistant.'), 'forbidden', 403);
        }

        if (! $claude->configured()) {
            return self::error(__('Add an Anthropic API key in Settings → Assistant first.'), 'not_configured', 409);
        }

        $data = $request->validate([
            'conversation' => ['nullable', 'string', 'size:26'],
            'message' => ['nullable', 'string', 'max:20000'],
            'edit' => ['sometimes', 'array:index,original'],
            'edit.index' => ['required_with:edit', 'integer', 'min:0'],
            'edit.original' => ['required_with:edit', 'string', 'max:20000'],
            'approve' => ['nullable', 'array', 'max:50'],
            'approve.*' => ['string', 'max:64'],
            'action' => ['nullable', 'array:tool,node,name'],
            'action.tool' => ['required_with:action', 'string', 'max:100'],
            'action.node' => ['required_with:action', 'string', 'max:80'],
            'action.name' => ['required_with:action', 'string', 'max:80'],
            'model' => ['nullable', 'string', Rule::in(array_keys(Claude::models()))],
            'context' => ['nullable', 'array'],
            'context.path' => ['nullable', 'string', 'max:300'],
            'context.title' => ['nullable', 'string', 'max:300'],
            'context.kind' => ['nullable', 'string', 'max:40'],
            'context.data' => ['nullable', 'string', 'max:40000'],
            'context.text' => ['nullable', 'string', 'max:12000'],
            'context.selection' => ['nullable', 'array:label,data'],
            'context.selection.label' => ['required_with:context.selection', 'string', 'max:200'],
            'context.selection.data' => ['nullable', 'string', 'max:12000'],
            'context.selected_text' => ['nullable', 'string', 'max:4000'],
            'context.unchanged' => ['nullable', 'boolean'],
        ]);

        $acting = ! empty($data['action']);
        $deciding = array_key_exists('approve', $data) && $data['approve'] !== null;
        $speaking = trim((string) ($data['message'] ?? '')) !== '';
        if (isset($data['edit']) && (! $speaking || $acting || $deciding || empty($data['conversation']))) {
            return self::error(__('Send one message, approval or interface action.'), 'invalid_input', 422);
        }
        if ((int) $acting + (int) $deciding + (int) $speaking !== 1 || (($acting || $deciding) && empty($data['conversation']))) {
            return self::error(__('Send one message, approval or interface action.'), 'invalid_input', 422);
        }

        $conversation = empty($data['conversation'])
            ? Conversation::query()->create(['user_id' => $user->id, 'messages' => []])
            : Conversation::query()->where('user_id', $user->id)->findOrFail($data['conversation']);

        // Saved with the turn; a request that fails before then leaves the chat's model as it was.
        if (! empty($data['model'])) {
            $conversation->model = $data['model'];
        }

        $lock = Cache::lock("cms-assistant:{$conversation->id}", 900);

        if (! $lock->get()) {
            return self::error(__('The assistant is still answering in this chat.'), 'busy', 409);
        }

        return new StreamedResponse(function () use ($agent, $conversation, $user, $data, $deciding, $acting, $lock): void {
            @set_time_limit(900);
            ignore_user_abort(true); // Finish the step in progress so the history stays consistent; stop before the next.

            // Shared hosts often buffer or compress output; events must reach the browser as they happen.
            if (! app()->runningUnitTests()) {
                @ini_set('zlib.output_compression', '0');
                function_exists('apache_setenv') && @apache_setenv('no-gzip', '1');

                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }

            $send = static function (string $event, array $payload): void {
                echo "event: {$event}\ndata: ".json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                @flush();
            };
            $stopped = static fn (): bool => connection_aborted() === 1;

            $send('conversation', $conversation->summary());

            try {
                if ($acting) {
                    $agent->interact($conversation, $user, $data['action'], $send, $stopped);
                } elseif ($deciding) {
                    $agent->decide($conversation, $user, array_values($data['approve']), $send, $stopped);
                } elseif (isset($data['edit'])) {
                    $agent->edit($conversation, $user, $data['edit'], trim((string) $data['message']), (array) ($data['context'] ?? []), $send, $stopped);
                } else {
                    $agent->send($conversation, $user, trim((string) $data['message']), (array) ($data['context'] ?? []), $send, $stopped);
                }

                $send('done', ['conversation' => $conversation->summary(), 'usage' => $conversation->usage, 'awaiting' => (bool) $conversation->pending]);
            } catch (Throwable $e) {
                $send('error', ['message' => self::explain($e)]);
            } finally {
                $lock->release();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private static function explain(Throwable $e): string
    {
        return match (true) {
            $e instanceof AbilityException => $e->getMessage(),
            $e instanceof AuthenticationException => __('Anthropic rejected the API key. Check it in Settings → Assistant.'),
            $e instanceof PermissionDeniedException => __('This API key cannot use the selected model.'),
            $e instanceof RateLimitException => __('Anthropic rate limit reached. Try again in a minute.'),
            $e instanceof BadRequestException => __('Anthropic rejected the request: :error', ['error' => $e->getMessage()]),
            $e instanceof APIStatusException && ($e->status ?? 0) >= 500 => __('Anthropic is overloaded or unavailable right now. Try again shortly.'),
            $e instanceof APIConnectionException => __('Could not reach the Anthropic API from this server.'),
            default => (static function () use ($e): string {
                report($e);

                return config('app.debug') ? $e->getMessage() : __('The assistant failed. Details are in the server log.');
            })(),
        };
    }

    private static function error(string $message, string $type, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['message' => $message, 'type' => $type]], $status);
    }
}

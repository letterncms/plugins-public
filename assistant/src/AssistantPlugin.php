<?php

declare(strict_types=1);

namespace Cms\Assistant;

use Illuminate\Support\Facades\Route;
use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Assistant\Http\ChatController;
use Cms\Packages\Plugin;
use Cms\Users\User;

/**
 * Claude inside the admin. It works only through abilities, as the signed-in
 * user and through the `assistant` transport — the same surface MCP agents
 * get — so it can never do more than the person could do by hand.
 */
final class AssistantPlugin extends Plugin
{
    public function register(): void
    {
        foreach ([Claude::class, Toolbox::class, Agent::class, Transcript::class, Translator::class, Helper::class] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $this->cms()->permissions()->register('assistant.use', __('Use the AI assistant'), __('Assistant'));
        // Whether there's an API key, for the admin: without one, only those who can add one see the chat (it opens the setup).
        $this->cms()->hooks()->addFilter('admin.manifest', fn (array $manifest) => [
            ...$manifest,
            'config' => [...($manifest['config'] ?? []), 'assistant' => ['configured' => $this->app->make(Claude::class)->configured()]],
        ]);
        $this->cms()->admin()->widget('assistant-ask', ['label' => 'Ask {brand}', 'description' => __('Ask the assistant from the home screen.'), 'icon' => 'sparkle', 'column' => 'side', 'order' => 15, 'permission' => 'assistant.use', 'assistant' => false]);

        $this->cms()->settingsRegistry()->register('assistant', __('Assistant'), [
            ['handle' => 'api_key', 'type' => 'text', 'label' => 'Anthropic API Key', 'secret' => true, 'instructions' => 'From console.anthropic.com → API keys. Stored encrypted; ANTHROPIC_API_KEY in .env works too. For an API gateway or proxy, set ANTHROPIC_BASE_URL in .env.'],
            ['handle' => 'model', 'type' => 'select', 'label' => 'Default Model', 'options' => array_map(static fn (array $model) => $model['name'], Claude::models()), 'default' => Claude::DEFAULT_MODEL, 'required' => true, 'display' => 'dropdown', 'width' => 'half'],
            ['handle' => 'effort', 'type' => 'select', 'label' => 'Effort', 'options' => Claude::efforts(), 'default' => 'medium', 'display' => 'dropdown', 'width' => 'half'],
            ['handle' => 'small_model', 'type' => 'select', 'label' => 'Model for Small Tasks', 'instructions' => 'Names chats and suggests what to do next. These run often, so a fast and inexpensive model is the right one.', 'options' => array_map(static fn (array $model) => $model['name'], Claude::models()), 'default' => Claude::DEFAULT_SMALL_MODEL, 'required' => true, 'display' => 'dropdown', 'width' => 'half'],
            ['handle' => 'approval', 'type' => 'select', 'label' => 'Ask Before', 'options' => ['destructive' => __('Anything live or hard to undo'), 'writes' => __('Every change')], 'default' => 'destructive', 'display' => 'radio'],
        ], icon: 'sparkle', description: __('The AI assistant in the admin: API key, model and when it asks for approval.'), package: $this->manifest->name, order: 60);

        Route::middleware(['web', 'cms.installed', 'auth:web', 'cms.admin', 'cms.locale', 'throttle:20,1'])
            ->post('/api/admin/assistant/chat', ChatController::class);

        $this->abilities();

        // The foreign key cascades too; this also covers databases that do not enforce it.
        User::deleted(static fn (User $user) => Conversation::query()->where('user_id', $user->id)->delete());

        // Machine translation for letterncms/i18n, when an API key is set.
        $this->cms()->hooks()->addFilter('i18n.translator', fn (?\Closure $translator) => $translator
            ?? ($this->app->make(Claude::class)->configured() ? $this->app->make(Translator::class)(...) : null));

        $this->adminModule(import: '@letterncms/assistant');
    }

    private function abilities(): void
    {
        $a = $this->cms()->abilities();
        // Conversations belong to the admin UI only: no tokens, agents or CLI.
        $expose = ['rest' => false, 'mcp' => false, 'cli' => false, 'assistant' => false];
        $mine = static fn (AbilityContext $c) => Conversation::query()->where('user_id', $c->user?->id ?? throw new AbilityException(__('Authentication required.'), 'unauthenticated'));

        $a->register('assistant/status', [
            'label' => 'Assistant Status',
            'description' => 'Whether the assistant is set up, the models to pick from and the default one.',
            'permission' => 'assistant.use',
            'annotations' => ['readOnly' => true],
            'expose' => $expose,
            'package' => $this->manifest->name,
            'handler' => fn () => [
                'configured' => $this->app->make(Claude::class)->configured(),
                'model' => $this->app->make(Claude::class)->model(),
                'models' => array_map(static fn (string $id, array $model) => ['id' => $id, ...$model], array_keys(Claude::models()), Claude::models()),
            ],
        ]);

        $a->register('assistant/skills', [
            'label' => 'List Assistant Skills',
            'description' => 'What / offers in a chat: the skills this person may use.',
            'permission' => 'assistant.use',
            'annotations' => ['readOnly' => true],
            'expose' => $expose,
            'package' => $this->manifest->name,
            'handler' => fn (array $in, AbilityContext $c) => array_values(array_map(static fn (array $skill) => [
                'name' => $skill['name'], 'label' => __($skill['label']), 'description' => __($skill['description']), 'icon' => $skill['icon'],
            ], $this->cms()->skills()->all($c->user))),
        ]);

        $a->register('assistant/resources', [
            'label' => 'List Assistant Resources',
            'description' => 'What @ offers in a chat besides content: the context resources this person may read.',
            'permission' => 'assistant.use',
            'annotations' => ['readOnly' => true],
            'expose' => $expose,
            'package' => $this->manifest->name,
            'handler' => fn (array $in, AbilityContext $c) => array_map(static fn (array $resource) => ['uri' => $resource['uri'], 'name' => __($resource['name'])], $this->cms()->context()->list($c->user)),
        ]);

        $a->register('assistant/resource', [
            'label' => 'Read Assistant Resource',
            'description' => 'One context resource, for a chat that attaches it.',
            'permission' => 'assistant.use',
            'annotations' => ['readOnly' => true],
            'expose' => $expose,
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['uri' => ['type' => 'string']], 'required' => ['uri']],
            'handler' => fn (array $in, AbilityContext $c) => $this->cms()->context()->read($in['uri'], $c->user)['text'] ?? throw new AbilityException(__('Not found.'), 'not_found'),
        ]);

        $a->register('assistant/suggest', [
            'label' => 'Suggest Next Steps',
            'description' => 'What to ask next, given what is on view. For a new chat.',
            'permission' => 'assistant.use',
            'annotations' => ['readOnly' => true],
            'expose' => $expose,
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 300],
                'kind' => ['type' => 'string', 'maxLength' => 40],
                'content' => ['type' => 'string', 'maxLength' => 8000],
                'selected' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 200], 'maxItems' => 8],
            ]],
            'handler' => fn (array $in) => $this->app->make(Claude::class)->configured() ? $this->app->make(Helper::class)->suggestions($in) : [],
        ]);

        $a->register('assistant/conversation-title', [
            'label' => 'Name Assistant Chat',
            'description' => 'Give one of your chats a short name from how it starts.',
            'permission' => 'assistant.use',
            'expose' => $expose,
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            'handler' => function (array $in, AbilityContext $c) use ($mine) {
                $conversation = $mine($c)->findOrFail($in['id']);
                $conversation->title = $this->app->make(Helper::class)->title($conversation);
                $conversation->save();

                return $conversation->summary();
            },
        ]);

        $a->register('assistant/conversations', [
            'label' => 'List Assistant Chats',
            'description' => 'Your recent chats with the assistant, newest first.',
            'permission' => 'assistant.use',
            'annotations' => ['readOnly' => true],
            'expose' => $expose,
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'offset' => ['type' => 'integer', 'minimum' => 0]]],
            'handler' => static fn (array $in, AbilityContext $c) => $mine($c)->latest('updated_at')->orderByDesc('id')->offset($in['offset'] ?? 0)->limit($in['limit'] ?? 30)->get()->map->summary()->all(),
        ]);

        $a->register('assistant/conversation', [
            'label' => 'Get Assistant Chat',
            'description' => 'One of your chats as shown in the panel, with calls waiting for approval.',
            'permission' => 'assistant.use',
            'annotations' => ['readOnly' => true],
            'expose' => $expose,
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            'handler' => function (array $in, AbilityContext $c) use ($mine) {
                $conversation = $mine($c)->findOrFail($in['id']);
                $agent = $this->app->make(Agent::class);

                return [
                    ...$conversation->summary(),
                    // Null for chats that follow the default model.
                    'model' => $conversation->model,
                    'items' => $this->app->make(Transcript::class)->of($conversation),
                    'awaiting' => array_map(static fn ($call) => $agent->describe($call, $conversation->untrusted), (array) ($conversation->pending['awaiting'] ?? [])),
                    'usage' => $conversation->usage,
                ];
            },
        ]);

        $a->register('assistant/conversation-delete', [
            'label' => 'Delete Assistant Chat',
            'description' => 'Delete one of your chats.',
            'permission' => 'assistant.use',
            'annotations' => ['destructive' => true],
            'expose' => $expose,
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            'handler' => static fn (array $in, AbilityContext $c) => ['deleted' => (bool) $mine($c)->whereKey($in['id'])->delete()],
        ]);
    }
}

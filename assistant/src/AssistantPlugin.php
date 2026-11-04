<?php

declare(strict_types=1);

namespace Cms\Assistant;

use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Packages\Plugin;

/**
 * Claude in the workspace: the agent that answers plain language in its threads
 * (Cms\Contracts\Agent). It works only through abilities, as the signed-in user
 * and through the `assistant` transport — the same surface MCP agents get — so it
 * can never do more than the person could do by hand.
 */
final class AssistantPlugin extends Plugin
{
    public function register(): void
    {
        foreach ([Claude::class, Toolbox::class, Agent::class, Translator::class, Helper::class] as $service) {
            $this->app->singleton($service);
        }

        $this->app->singleton(\Cms\Contracts\Agent::class, static fn ($app) => $app->make(Agent::class));
    }

    public function boot(): void
    {
        $this->cms()->permissions()->register('assistant.use', __('Use the AI Assistant'), __('Assistant'));
        $this->cms()->admin()->widget('assistant-ask', ['label' => 'Ask {brand}', 'description' => __('Ask the assistant from the Dashboard.'), 'icon' => 'sparkle', 'column' => 'side', 'order' => 15, 'permission' => 'assistant.use', 'assistant' => false]);

        $this->cms()->settingsRegistry()->register('assistant', fields: [
            ['handle' => 'api_key', 'type' => 'text', 'label' => 'Anthropic API Key', 'secret' => true, 'instructions' => 'From console.anthropic.com.'],
            ['handle' => 'model', 'type' => 'select', 'label' => 'Default Model', 'options' => array_map(static fn (array $model) => $model['name'], Claude::models()), 'default' => Claude::DEFAULT_MODEL, 'required' => true, 'display' => 'dropdown', 'width' => 'half'],
            ['handle' => 'effort', 'type' => 'select', 'label' => 'Effort', 'options' => Claude::efforts(), 'default' => 'medium', 'display' => 'dropdown', 'width' => 'half'],
            ['handle' => 'small_model', 'type' => 'select', 'label' => 'Model for Small Tasks', 'instructions' => 'Names chats. A fast model is enough.', 'options' => array_map(static fn (array $model) => $model['name'], Claude::models()), 'default' => Claude::DEFAULT_SMALL_MODEL, 'required' => true, 'display' => 'dropdown', 'width' => 'half'],
            ['handle' => 'approval', 'type' => 'select', 'label' => 'Ask Before', 'options' => ['destructive' => __('Deletes and Admin Changes'), 'writes' => __('Every Change')], 'default' => 'destructive', 'display' => 'radio'],
        ], icon: 'sparkle', description: __('API key, models and approvals.'), package: $this->manifest->name);

        $this->abilities();

        // Machine translation for letterncms/languages, when an API key is set.
        $this->cms()->hooks()->addFilter('languages.translator', fn (?\Closure $translator) => $translator
            ?? ($this->app->make(Claude::class)->configured() ? $this->app->make(Translator::class)(...) : null));

        $this->adminModule();
    }

    private function abilities(): void
    {
        $a = $this->cms()->abilities();
        // What the workspace's composer asks for: the admin's own, no tokens, agents or CLI.
        $expose = ['rest' => false, 'mcp' => false, 'cli' => false, 'assistant' => false];

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
            'description' => 'What / offers in a thread: the skills this person may use.',
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
            'description' => 'What @ offers in a thread besides content: the context resources this person may read.',
            'permission' => 'assistant.use',
            'annotations' => ['readOnly' => true],
            'expose' => $expose,
            'package' => $this->manifest->name,
            'handler' => fn (array $in, AbilityContext $c) => array_map(static fn (array $resource) => ['uri' => $resource['uri'], 'name' => __($resource['name'])], $this->cms()->context()->list($c->user)),
        ]);

        $a->register('assistant/resource', [
            'label' => 'Read Assistant Resource',
            'description' => 'One context resource, for a message that attaches it.',
            'permission' => 'assistant.use',
            'annotations' => ['readOnly' => true],
            'expose' => $expose,
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['uri' => ['type' => 'string']], 'required' => ['uri']],
            'handler' => fn (array $in, AbilityContext $c) => $this->cms()->context()->read($in['uri'], $c->user)['text'] ?? throw new AbilityException(__('Not found.'), 'not_found'),
        ]);
    }
}

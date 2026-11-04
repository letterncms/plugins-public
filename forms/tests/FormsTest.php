<?php

declare(strict_types=1);

namespace Cms\Forms\Tests;

use Cms\Forms\FormRepository;
use Cms\Forms\Submission;
use Cms\Abilities\AbilityContext;
use Cms\Tests\TestCase;

final class FormsTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks', 'letterncms/forms'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(FormRepository::class)->save('quote', [
            'title' => 'Quote',
            'fields' => [
                ['type' => 'form/text', 'props' => ['label' => 'Name', 'name' => 'name', 'required' => true]],
                ['type' => 'form/email', 'props' => ['label' => 'Email', 'name' => 'email', 'required' => true]],
                ['type' => 'form/radio', 'props' => ['label' => 'Type', 'name' => 'type', 'options' => "site|Website\napp|App"]],
                ['type' => 'form/text', 'props' => ['label' => 'App store link', 'name' => 'store', 'required' => true, 'show_if' => ['field' => 'type', 'op' => 'equals', 'value' => 'app']]],
            ],
            'settings' => ['min_seconds' => 3],
        ]);
    }

    public function test_a_forms_fields_are_not_blocks_of_the_page_it_is_embedded_in(): void
    {
        $renderer = app(\Cms\Blocks\BlockRenderer::class);
        $renderer->editor = true;

        $html = $renderer->withEditorDocument('page', fn () => $renderer->render([['id' => 'embed', 'type' => 'forms/form', 'props' => ['form' => 'quote']]]));

        $this->assertStringContainsString('data-cms-node="embed"', $html);
        $this->assertStringContainsString('name="email"', $html);
        // Anything else the canvas found in this document, it would remove as deleted.
        $this->assertSame(1, substr_count($html, 'data-cms-node='));
    }

    public function test_valid_submission_is_stored(): void
    {
        $this->postJson('/api/forms/quote', ['name' => 'Ada', 'email' => 'ada@example.com', 'type' => 'site'])->assertOk()->assertJsonPath('ok', true);

        $s = Submission::query()->first();
        $this->assertSame(['name' => 'Ada', 'email' => 'ada@example.com', 'type' => 'site'], $s->data);
        $this->assertSame('new', $s->status);
    }

    public function test_the_ip_hash_is_only_comparable_within_a_day(): void
    {
        $submit = fn () => $this->postJson('/api/forms/quote', ['name' => 'Ada', 'email' => 'ada@example.com', 'type' => 'site'])->assertOk();

        $submit();
        $submit();
        $this->travel(1)->days();
        $submit();

        $hashes = Submission::query()->orderBy('created_at')->get()->map(static fn ($s) => $s->meta['ip_hash'])->all();
        $this->assertSame($hashes[0], $hashes[1]);
        $this->assertNotSame($hashes[0], $hashes[2]);
        // Not derived from anything that stays: the old salt is gone, and nothing can link the days.
        $this->assertStringNotContainsString($hashes[0], (string) file_get_contents(storage_path('app/salts/forms.json')));
        $this->assertNotSame(hash_hmac('sha256', '127.0.0.1', (string) config('app.key')), $hashes[0]);
    }

    public function test_conditional_fields_are_validated_only_when_visible(): void
    {
        $this->postJson('/api/forms/quote', ['name' => 'Ada', 'email' => 'ada@example.com', 'type' => 'app'])->assertStatus(422)->assertJsonPath('errors.store', 'App store link is required.');
        $this->postJson('/api/forms/quote', ['name' => 'Ada', 'email' => 'ada@example.com', 'type' => 'site', 'store' => 'ignored'])->assertOk();
        $this->assertArrayNotHasKey('store', Submission::query()->first()->data);
    }

    public function test_invalid_options_and_emails_are_rejected(): void
    {
        $this->postJson('/api/forms/quote', ['name' => 'Ada', 'email' => 'nope', 'type' => 'evil'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email', 'Enter a valid email address.')
            ->assertJsonPath('errors.type', 'Choose one of the options.');
    }

    public function test_spam_is_silently_dropped(): void
    {
        $this->postJson('/api/forms/quote', ['name' => 'Bot', 'email' => 'b@x.com', '_cms_website' => 'x'])->assertOk();
        $this->postJson('/api/forms/quote', ['name' => 'Fast', 'email' => 'f@x.com', '_cms_t' => (string) (time() * 1000)])->assertOk();

        $this->assertSame(0, Submission::query()->count());
    }

    public function test_foreign_origins_are_refused_and_no_js_redirects(): void
    {
        $this->postJson('/api/forms/quote', ['name' => 'X', 'email' => 'x@x.com'], ['Origin' => 'https://evil.example'])->assertForbidden();

        $this->post('/api/forms/quote', ['name' => 'Ada', 'email' => 'ada@example.com'], ['Referer' => 'http://localhost/contact'])
            ->assertStatus(303)
            ->assertRedirect('http://localhost/contact#cms-form-quote-ok');
    }

    public function test_duplicate_field_names_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(FormRepository::class)->save('dupe', ['title' => 'Dupe', 'fields' => [
            ['type' => 'form/text', 'props' => ['label' => 'A', 'name' => 'x']],
            ['type' => 'form/text', 'props' => ['label' => 'B', 'name' => 'x']],
        ]]);
    }

    public function test_activity_summarizes_recent_submissions(): void
    {
        Submission::query()->create(['form' => 'quote', 'data' => ['type' => 'app', 'name' => "  Grace\n Hopper "], 'status' => 'new']);
        Submission::query()->create(['form' => 'quote', 'data' => ['name' => 'Bot'], 'status' => 'spam']);

        $this->actingAs($this->admin());
        $data = $this->postJson('/api/admin/abilities/forms/activity', [])->assertOk()->json('data');

        $this->assertSame(1, $data['forms']);
        $this->assertSame(1, $data['new']);
        $this->assertCount(14, $data['days']);
        $this->assertSame(1, $data['days'][13]);
        $this->assertCount(1, $data['latest']);
        $this->assertSame(['form' => 'quote', 'formTitle' => 'Quote', 'summary' => 'Grace Hopper', 'status' => 'new'], array_intersect_key($data['latest'][0], array_flip(['form', 'formTitle', 'summary', 'status'])));
    }

    /** @return array the view as the admin draws it for this person */
    private function place(string $id, array $params = []): array
    {
        return $this->cms()->abilities()->execute('views/get', ['id' => $id, 'params' => $params], AbilityContext::forUser($this->admin(), 'admin'))['root'];
    }

    public function test_the_forms_places_are_views(): void
    {
        $this->assertSame('ui/records', $this->place('forms')['children'][0]['children'][0]['children'][0]['type']);
        $tabs = array_column(array_column($this->place('form', ['handle' => 'quote'])['children'][0]['children'], 'props'), 'id');
        $this->assertSame(count($this->cms()->locales()) > 1 ? ['fields', 'options', 'languages', 'submissions'] : ['fields', 'options', 'submissions'], $tabs);
        $this->assertSame('ui/empty', $this->place('form', ['handle' => 'gone'])['children'][0]['type']);
    }

    public function test_a_form_page_renames_and_deletes_on_every_tab_and_reads_for_viewers(): void
    {
        $page = $this->place('form', ['handle' => 'quote']);
        $this->assertSame(['Rename', 'Delete'], array_column($page['props']['actions'], 'label'));
        $this->assertSame('Delete “Quote”?', $page['props']['actions'][1]['confirm']['title']);

        $this->cms()->settingsRegistry()->save('roles', ['roles' => ['viewer' => ['label' => 'Viewer', 'permissions' => ['admin.access', 'forms.view']]]]);
        $viewer = $this->userWithRole('viewer');
        $page = $this->cms()->abilities()->execute('views/get', ['id' => 'form', 'params' => ['handle' => 'quote']], AbilityContext::forUser($viewer, 'admin'))['root'];
        $this->assertArrayNotHasKey('actions', array_filter($page['props'], static fn ($v) => $v !== []));
        $settings = $page['children'][0]['children'][1];
        $this->assertSame('options', $settings['props']['id']);
        $this->assertSame(['ui/section', 'ui/detail'], [$settings['children'][0]['type'], $settings['children'][0]['children'][0]['type']]);
        $this->assertContains('Accept Submissions', array_column($settings['children'][0]['children'][0]['props']['items'], 'label'));
    }

    public function test_a_form_is_created_and_changed_a_part_at_a_time(): void
    {
        $this->actingAs($this->admin());
        $run = fn (string $ability, array $input) => $this->postJson("/api/admin/abilities/{$ability}", $input)->assertOk()->json('data');

        $form = $run('forms/create', ['title' => 'Quote']);
        $this->assertSame('quote-2', $form['handle']);
        $this->assertCount(3, $form['fields']);

        $this->assertSame('Sent!', $run('forms/configure', ['handle' => 'quote', 'settings' => ['submit' => 'Sent!']])['submit']);
        $this->assertSame(['de:s:submit' => 'Senden'], $run('forms/translate', ['handle' => 'quote', 'words' => ['de:s:submit' => 'Senden', 'de:f:name:label' => '']]));
        $run('forms/save', ['handle' => 'quote', 'title' => 'Quotes']);

        $quote = app(FormRepository::class)->find('quote');
        $this->assertSame('Quotes', $quote['title']);
        $this->assertSame('Sent!', $quote['settings']['submit']);
        $this->assertSame(3, $quote['settings']['min_seconds']);
        $this->assertSame(['de' => ['settings' => ['submit' => 'Senden']]], $quote['locales']);
        $this->assertCount(4, $quote['fields']);
    }

    public function test_opening_a_submission_reads_it_and_the_export_downloads(): void
    {
        $s = Submission::query()->create(['form' => 'quote', 'data' => ['name' => 'Ada', 'email' => 'ada@example.com'], 'status' => 'new']);
        $this->actingAs($this->admin());

        $this->postJson('/api/admin/abilities/forms/submission', ['id' => $s->id])->assertOk()->assertJsonPath('data.labels.email', 'Email');
        $this->assertSame('read', $s->fresh()->status);

        $csv = $this->get('/api/admin/forms/quote/export')->assertOk()->assertHeader('Content-Disposition')->getContent();
        $this->assertStringContainsString('Ada,ada@example.com', $csv);
    }

    public function test_a_new_form_is_answered_in_the_workspace_with_a_way_to_it_and_back(): void
    {
        $admin = $this->admin();
        $form = $this->cms()->abilities()->execute('forms/create', ['title' => 'Bookings'], \Cms\Abilities\AbilityContext::forUser($admin, 'admin'));
        $view = $this->app->make(\Cms\Modules\Workspace\Answers::class)->command($this->cms()->abilities()->get('forms/create'), ['title' => 'Bookings'], $form, $admin);

        $this->assertSame('Created Bookings', $view['children'][0]['props']['title']);
        $this->assertSame([['Open', '/forms/'.$form['handle']], ['Delete', null]], array_map(static fn ($a) => [$a['label'], $a['to'] ?? null], $view['children'][1]['props']['items']));
    }
}

<?php

declare(strict_types=1);

namespace Cms\Brevo\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Brevo\Client;
use Cms\Brevo\Signup;
use Cms\Modules\Blocks\BlockRenderer;
use Cms\Modules\Email\Mailer;
use Cms\Modules\Email\Message;
use Cms\Tests\TestCase;

/** Newsletter signups: the block, double opt-in and the lists, against a Brevo that Http::fake() plays. */
final class SignupTest extends TestCase
{
    /** @var list<Message> */
    private array $sent = [];

    protected function plugins(): array
    {
        return ['letterncms/brevo', 'letterncms/blocks', 'letterncms/site'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // The list first: with a key, saving asks Brevo which lists there are to choose from.
        $this->cms()->settingsRegistry()->save('brevo', ['list' => '#7']);
        $this->cms()->settingsRegistry()->save('brevo', ['api_key' => 'list-key']);

        $sent = &$this->sent;
        $this->app->instance(Mailer::class, new class($sent) extends Mailer
        {
            public function __construct(private array &$sent) {}

            public function send(Message $message): void
            {
                $this->sent[] = $message;
            }
        });
    }

    private function signUp(array $fields, array $headers = ['Accept' => 'application/json']): \Illuminate\Testing\TestResponse
    {
        $signup = app(Signup::class);

        return $this->withHeaders($headers)->post('/api/brevo/subscribe', ['email' => 'ada@example.com', 'list' => 7, 'seal' => $signup->seal(7), ...$fields]);
    }

    private function link(): string
    {
        $this->assertCount(1, $this->sent);
        $this->assertSame(1, preg_match('#/api/brevo/confirm\?token=[A-Za-z0-9_.%-]+#', $this->sent[0]->text, $m));

        return $m[0];
    }

    /** What the button of the page behind the link posts. */
    private function confirm(string $link): \Illuminate\Testing\TestResponse
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        return $this->post('/api/brevo/confirm', ['token' => $query['token']]);
    }

    public function test_a_signup_is_mailed_a_link_and_nothing_reaches_brevo(): void
    {
        Http::fake();

        $this->signUp(['email' => ' Ada@Example.com '])->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame('ada@example.com', $this->sent[0]->to);
        $this->assertSame('Confirm your subscription', $this->sent[0]->subject);
        $this->assertStringContainsString('Confirm Subscription', $this->sent[0]->html);
        $this->link();
        Http::assertNothingSent();
    }

    public function test_following_the_link_only_asks_and_its_button_puts_the_address_on_its_list(): void
    {
        Http::fake(['api.brevo.com/v3/contacts' => Http::response(['id' => 1], 201)]);
        $subscribed = [];
        $this->cms()->hooks()->on('brevo.subscribed', function (string $email, int $list) use (&$subscribed) {
            $subscribed[] = [$email, $list];
        });

        $this->signUp(['list' => 12, 'seal' => app(Signup::class)->seal(12)])->assertOk();
        // A mail scanner that follows the link, any number of times, subscribes nobody.
        $page = $this->get($this->link())->assertOk()->assertSee('Add ada@example.com to the list?')->assertSee('method="post"', false)->assertSee('noindex', false);
        $this->get($this->link())->assertOk();
        $this->assertSame('no-store, private', $page->headers->get('Cache-Control'));
        $this->assertStringContainsString("form-action 'self'", (string) $page->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('<script', (string) $page->getContent());
        $this->assertStringNotContainsString('script-src', (string) $page->headers->get('Content-Security-Policy'));
        Http::assertNothingSent();
        $this->assertSame([], $subscribed);

        $this->confirm($this->link())->assertOk()->assertSee('You’re subscribed');

        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $r) => $r->method() === 'POST' && $r->hasHeader('api-key', 'list-key') && $r->data() === ['email' => 'ada@example.com', 'listIds' => [12], 'updateEnabled' => true]);
        $this->assertSame([['ada@example.com', 12]], $subscribed);
    }

    public function test_a_confirmed_signup_can_lead_to_a_page_of_the_site(): void
    {
        Http::fake(['api.brevo.com/v3/contacts' => Http::response(null, 204)]);
        $this->cms()->settingsRegistry()->save('brevo', ['confirmed_redirect' => '/thanks']);

        $this->signUp([])->assertOk();
        $this->confirm($this->link())->assertRedirect('/thanks');
    }

    public function test_a_changed_or_old_link_is_refused(): void
    {
        Http::fake();
        $signup = app(Signup::class);
        $token = $signup->token('ada@example.com', 7, 'en');
        [$payload, $signature] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode((string) json_encode(['e' => 'eve@example.com', 'l' => 7, 'o' => 'en', 'x' => time() + 3600])), '+/', '-_'), '=');

        $this->assertSame(['email' => 'ada@example.com', 'list' => 7, 'locale' => 'en'], $signup->read($token));
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        foreach ([$forged.'.'.$signature, $payload.'.'.strrev($signature), $payload, '', $signup->token('ada@example.com', 7, 'en', time() - 1)] as $bad) {
            $this->assertNull($signup->read($bad));
            $this->get('/api/brevo/confirm?token='.rawurlencode($bad))->assertStatus(410);
            $this->post('/api/brevo/confirm', ['token' => $bad])->assertStatus(410);
        }

        Http::assertNothingSent();
    }

    public function test_one_address_is_mailed_a_few_times_an_hour_and_answered_the_same_after_that(): void
    {
        Http::fake();

        for ($i = 0; $i < Signup::MAILS; $i++) {
            $this->signUp([])->assertOk()->assertExactJson(['ok' => true]);
        }

        $this->assertCount(Signup::MAILS, $this->sent);

        // Written another way, it is the same inbox; the answer says nothing of the limit.
        $this->signUp(['email' => 'ADA@example.com'])->assertOk()->assertExactJson(['ok' => true]);
        $this->assertCount(Signup::MAILS, $this->sent);

        $this->signUp(['email' => 'grace@example.com'])->assertOk();
        $this->assertCount(Signup::MAILS + 1, $this->sent);
        Http::assertNothingSent();
    }

    public function test_a_list_the_page_did_not_offer_is_refused(): void
    {
        Http::fake();

        $this->signUp(['list' => 99])->assertStatus(422);
        $this->signUp(['email' => 'not-an-address'])->assertStatus(422);

        $this->assertSame([], $this->sent);
        Http::assertNothingSent();
    }

    public function test_a_filled_trap_is_answered_like_a_signup_and_dropped(): void
    {
        Http::fake();

        $this->signUp(['_cms_website' => 'https://spam.example'])->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame([], $this->sent);
        Http::assertNothingSent();
    }

    public function test_another_website_cannot_post_signups(): void
    {
        Http::fake();

        $this->signUp([], ['Accept' => 'application/json', 'Origin' => 'https://elsewhere.example'])->assertStatus(403);
        $this->assertSame([], $this->sent);
    }

    public function test_without_a_script_the_page_comes_back_with_the_answer(): void
    {
        Http::fake();

        $this->signUp(['_cms_id' => 'brevo-signup-abc123'], ['Referer' => 'http://localhost/news#top'])->assertRedirect('http://localhost/news#brevo-signup-abc123-ok');
        $this->signUp(['email' => 'nope', '_cms_id' => 'brevo-signup-abc123'], ['Referer' => 'http://localhost/news'])->assertRedirect('http://localhost/news#brevo-signup-abc123-error');
    }

    public function test_without_double_opt_in_the_address_is_added_right_away(): void
    {
        $this->cms()->settingsRegistry()->save('brevo', ['double_opt_in' => false]);
        Http::fake(['api.brevo.com/v3/contacts' => Http::response(['id' => 1], 201)]);

        $this->signUp([])->assertOk();

        $this->assertSame([], $this->sent);
        Http::assertSent(static fn (Request $r) => $r->data()['listIds'] === [7]);
    }

    public function test_a_brevo_that_fails_is_reported_without_saying_why(): void
    {
        $this->cms()->settingsRegistry()->save('brevo', ['double_opt_in' => false]);
        Http::fake(['api.brevo.com/*' => Http::response(['message' => 'Key not found'], 401)]);

        $this->signUp([])->assertStatus(503)->assertJsonMissing(['message' => 'Key not found']);

        // And on confirming: the link stays good for another try.
        $this->post('/api/brevo/confirm', ['token' => app(Signup::class)->token('ada@example.com', 7, 'en')])->assertStatus(503);
    }

    public function test_the_block_renders_a_form_for_its_list(): void
    {
        Http::fake();
        $renderer = app(BlockRenderer::class);
        $html = $renderer->render([['id' => 'news01', 'type' => 'brevo/signup', 'props' => ['list' => '#12', 'button' => 'Join', 'consent' => 'One email a month.']]]);

        $this->assertStringContainsString('action="/api/brevo/subscribe"', $html);
        $this->assertStringContainsString('type="email" name="email"', $html);
        $this->assertStringContainsString('name="list" value="12"', $html);
        $this->assertStringContainsString('name="seal" value="'.app(Signup::class)->seal(12).'"', $html);
        $this->assertStringContainsString('<span>Join</span>', $html);
        $this->assertStringContainsString('One email a month.', $html);
        $this->assertStringContainsString('follow the link in the email', $html);

        // Without a list of its own it takes the default; with none at all it shows nothing to visitors.
        $this->assertStringContainsString('name="list" value="7"', $renderer->render([['id' => 'news02', 'type' => 'brevo/signup', 'props' => []]]));
        $this->cms()->settingsRegistry()->save('brevo', ['list' => null]);
        $this->assertStringNotContainsString('<form', $renderer->render([['id' => 'news03', 'type' => 'brevo/signup', 'props' => []]]));
    }

    public function test_lists_come_from_brevo_and_are_cached(): void
    {
        Http::fake(['api.brevo.com/v3/contacts/lists*' => Http::response(['lists' => [['id' => 7, 'name' => 'Newsletter', 'uniqueSubscribers' => 42], ['id' => 12, 'name' => 'Customers', 'totalSubscribers' => 9]], 'count' => 2])]);

        $lists = $this->cms()->abilities()->execute('brevo/lists', [], AbilityContext::system());
        $this->cms()->abilities()->execute('brevo/lists', [], AbilityContext::system());

        $this->assertSame(['state' => 'ok', 'lists' => [['id' => 7, 'name' => 'Newsletter', 'subscribers' => 42], ['id' => 12, 'name' => 'Customers', 'subscribers' => 9]]], $lists);
        $this->assertSame(['#7' => 'Newsletter', '#12' => 'Customers'], $this->cms()->hooks()->filter('fields.options.brevo-lists', []));
        Http::assertSentCount(1);

        // The settings offer them, and take one.
        $this->cms()->settingsRegistry()->save('brevo', ['list' => '#12']);
        $this->assertSame(12, app(Signup::class)->list());
    }

    public function test_every_list_is_fetched_a_page_at_a_time(): void
    {
        $page = static fn (int $from, int $to) => array_map(static fn (int $id) => ['id' => $id, 'name' => 'List '.$id], range($from, $to));
        Http::fake(['api.brevo.com/v3/contacts/lists*' => Http::sequence()
            ->push(['lists' => $page(1, 50), 'count' => 120])
            ->push(['lists' => $page(51, 100), 'count' => 120])
            ->push(['lists' => $page(101, 120), 'count' => 120])]);

        $lists = app(Client::class)->lists();

        $this->assertSame('ok', $lists['state']);
        $this->assertSame(range(1, 120), array_column($lists['lists'], 'id'));
        Http::assertSentCount(3);
        Http::assertSent(static fn (Request $r) => str_contains($r->url(), 'offset=100') && str_contains($r->url(), 'limit=50'));
    }

    public function test_a_page_that_fails_is_an_error_not_half_the_lists(): void
    {
        Http::fake(['api.brevo.com/v3/contacts/lists*' => Http::sequence()
            ->push(['lists' => array_map(static fn (int $id) => ['id' => $id, 'name' => 'List '.$id], range(1, 50)), 'count' => 80])
            ->push('oops', 500)]);

        $this->assertSame(['error', []], [app(Client::class)->lists()['state'], app(Client::class)->lists()['lists']]);
    }

    public function test_the_mail_transports_key_is_the_one_key_and_the_page_asks_for_no_other(): void
    {
        $registry = $this->cms()->settingsRegistry();
        $handles = static fn () => array_column($registry->schemas()['brevo']['fields'], 'handle');
        $this->assertContains('api_key', $handles());

        $registry->save('brevo', ['api_key' => '']);
        $this->assertSame('no-key', app(Client::class)->lists()['state']);

        $registry->save('brevo', ['api_key' => 'list-key']);
        $registry->save('email', ['transport' => 'brevo', 'brevo_api_key' => 'mail-key']);
        $this->assertSame('mail-key', app(Client::class)->key());
        $this->assertNotContains('api_key', $handles(), 'Set under Settings → Email, it is not asked for again.');
        $this->assertNotContains('endpoint', $handles());

        // Saved while the field is away, its old value goes with it: never into the settings file.
        $registry->save('brevo', ['list' => '#12', 'api_key' => 'sneaked-in']);
        $this->assertStringNotContainsString('key', (string) file_get_contents($this->site.'/config/brevo.yaml'));
        $this->assertSame('mail-key', app(Client::class)->key());
    }

    public function test_pages_hosted_elsewhere_post_to_the_address_forms_posts_to(): void
    {
        $renderer = app(BlockRenderer::class);
        $this->assertStringContainsString('action="/api/brevo/subscribe"', $renderer->render([['id' => 'news04', 'type' => 'brevo/signup', 'props' => []]]));

        // Forms' own setting, whether or not Forms is installed: one place says where the CMS answers.
        $this->cms()->settingsRegistry()->save('forms', ['endpoint' => 'https://cms.example.com/']);
        $this->assertSame('https://cms.example.com', app(Signup::class)->base());
        $this->assertStringContainsString('action="https://cms.example.com/api/brevo/subscribe"', $renderer->render([['id' => 'news05', 'type' => 'brevo/signup', 'props' => []]]));

        Http::fake();
        $this->signUp([])->assertOk();
        $this->assertStringContainsString('https://cms.example.com/api/brevo/confirm?token=', $this->sent[0]->text);
    }

    public function test_an_agent_adds_someone_who_agreed(): void
    {
        Http::fake(['api.brevo.com/v3/contacts' => Http::response(['id' => 1], 201)]);

        $this->assertSame(['email' => 'grace@example.com', 'list' => 7], $this->cms()->abilities()->execute('brevo/subscribe', ['email' => 'Grace@example.com'], AbilityContext::system()));

        $this->cms()->settingsRegistry()->save('brevo', ['list' => null]);
        $this->expectException(AbilityException::class);
        $this->cms()->abilities()->execute('brevo/subscribe', ['email' => 'grace@example.com'], AbilityContext::system());
    }
}

<?php

declare(strict_types=1);

namespace Cms\Forms\Tests;

use Cms\Forms\Captcha\Captchas;
use Cms\Forms\Captcha\Client;
use Cms\Forms\Captcha\HCaptcha;
use Cms\Forms\Captcha\Turnstile;
use Cms\Forms\FormRenderer;
use Cms\Forms\FormRepository;
use Cms\Forms\Submission;
use Cms\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Both providers against a stand-in for their verify endpoint: no network, no keys. */
final class CaptchaTest extends TestCase
{
    /** @var list<array{url: string, fields: array<string, string>}> what the provider was asked */
    private array $asked = [];

    protected function plugins(): array
    {
        return ['letterncms/forms'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(FormRepository::class)->save('quote', [
            'title' => 'Quote',
            'fields' => [['type' => 'form/email', 'props' => ['label' => 'Email', 'name' => 'email', 'required' => true]]],
        ]);
    }

    /** Each provider's token field and where it is asked. */
    private const array EXPECTED = [
        'turnstile' => ['cf-turnstile-response', Turnstile::VERIFY],
        'hcaptcha' => ['h-captcha-response', HCaptcha::VERIFY],
    ];

    /** @return array<string, array{0: string}> */
    public static function providers(): array
    {
        return ['Turnstile' => ['turnstile'], 'hCaptcha' => ['hcaptcha']];
    }

    /** The provider answers `success` for the token "good" (or `$accept`), fails any other, and is down when `$up` is false. */
    private function provider(string $name, bool $up = true, string $accept = 'good'): void
    {
        $this->cms()->settingsRegistry()->save('forms', ['captcha' => $name, 'captcha_site_key' => 'site-key', 'captcha_secret' => 'secret-key']);
        $this->app->instance(Client::class, new class($this->asked, $up, $accept) implements Client
        {
            public function __construct(private array &$asked, private readonly bool $up, private readonly string $accept) {}

            public function post(string $url, array $fields): ?array
            {
                $this->asked[] = ['url' => $url, 'fields' => $fields];

                return $this->up ? ['success' => $fields['response'] === $this->accept] : null;
            }
        });
    }

    #[DataProvider('providers')]
    public function test_a_submission_with_a_token_the_provider_vouches_for_is_stored(string $name): void
    {
        [$field, $url] = self::EXPECTED[$name];
        $this->provider($name);

        $this->postJson('/api/forms/quote', ['email' => 'ada@example.com', $field => 'good'])->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(1, Submission::query()->count());
        $this->assertSame($url, $this->asked[0]['url']);
        $this->assertSame('secret-key', $this->asked[0]['fields']['secret']);
        $this->assertSame('good', $this->asked[0]['fields']['response']);
        // The token never ends up among the answers.
        $this->assertSame(['email' => 'ada@example.com'], Submission::query()->first()->data);
    }

    #[DataProvider('providers')]
    public function test_a_token_the_provider_rejects_is_refused_and_the_visitor_is_told(string $name): void
    {
        [$field] = self::EXPECTED[$name];
        $this->provider($name);

        $this->postJson('/api/forms/quote', ['email' => 'ada@example.com', $field => 'forged'])
            ->assertStatus(422)
            ->assertJsonPath('captcha', true)
            ->assertJsonPath('message', 'We could not confirm you are a person. Please try again.')
            ->assertJsonPath('errors', []);

        $this->assertSame(0, Submission::query()->count());
    }

    #[DataProvider('providers')]
    public function test_a_missing_token_is_refused_without_asking_the_provider(string $name): void
    {
        $this->provider($name);

        $this->postJson('/api/forms/quote', ['email' => 'ada@example.com'])->assertStatus(422)->assertJsonPath('captcha', true);
        $this->postJson('/api/forms/quote', ['email' => 'ada@example.com', 'cf-turnstile-response' => ['good'], 'h-captcha-response' => ['good']])->assertStatus(422);

        $this->assertSame([], $this->asked);
        $this->assertSame(0, Submission::query()->count());
    }

    #[DataProvider('providers')]
    public function test_a_provider_that_is_down_lets_nothing_through_says_so_and_is_reported(string $name): void
    {
        [$field] = self::EXPECTED[$name];
        $this->provider($name, up: false);
        \Illuminate\Support\Facades\Exceptions::fake();

        $down = $this->postJson('/api/forms/quote', ['email' => 'ada@example.com', $field => 'good'])->assertStatus(503)->assertJsonPath('captcha', true)->json('message');

        $this->assertCount(1, $this->asked);
        $this->assertSame(0, Submission::query()->count());
        \Illuminate\Support\Facades\Exceptions::assertReported(static fn (\RuntimeException $e) => str_contains($e->getMessage(), 'could not be asked') && str_contains($e->getMessage(), 'quote'));

        // Not the visitor's doing: other words than for a token that failed, and a place of its own without a script.
        $this->provider($name);
        $failed = $this->postJson('/api/forms/quote', ['email' => 'ada@example.com', $field => 'bad'])->assertStatus(422)->json('message');
        $this->assertSame('We could not confirm you are a person. Please try again.', $failed);
        $this->assertStringContainsString('right now', $down);
        $this->assertNotSame($failed, $down);

        $this->provider($name, up: false);
        $this->post('/api/forms/quote', ['email' => 'ada@example.com', $field => 'good'], ['Referer' => 'http://localhost/contact'])
            ->assertRedirect('http://localhost/contact#cms-form-quote-captcha-down');
        $this->assertStringContainsString('id="cms-form-quote-captcha-down"', app(FormRenderer::class)->render('quote'));
    }

    public function test_a_token_as_long_as_a_provider_makes_them_passes_and_a_longer_one_is_not_sent_on(): void
    {
        // hCaptcha's signed tokens run to several thousand characters; Turnstile's stay under 2048.
        foreach (['hcaptcha' => 8192, 'turnstile' => 2048] as $name => $max) {
            $this->provider($name, accept: $long = str_repeat('a', $max));
            [$field] = self::EXPECTED[$name];
            $this->asked = [];

            $this->postJson('/api/forms/quote', ['email' => 'ada@example.com', $field => $long])->assertOk();
            $this->postJson('/api/forms/quote', ['email' => 'ada@example.com', $field => $long.'a'])->assertStatus(422)->assertJsonPath('captcha', true);
            $this->assertCount(1, $this->asked, 'A token longer than the provider makes is refused here.');
        }
    }

    #[DataProvider('providers')]
    public function test_fields_are_checked_first_so_a_token_is_not_spent_on_a_submission_that_comes_back(string $name): void
    {
        [$field] = self::EXPECTED[$name];
        $this->provider($name);

        $this->postJson('/api/forms/quote', ['email' => 'not-an-email', $field => 'good'])->assertStatus(422)->assertJsonMissingPath('captcha')->assertJsonValidationErrorFor('email');

        $this->assertSame([], $this->asked);
    }

    #[DataProvider('providers')]
    public function test_without_a_script_the_visitor_lands_on_the_message(string $name): void
    {
        $this->provider($name);

        $this->post('/api/forms/quote', ['email' => 'ada@example.com'], ['Referer' => 'http://localhost/contact'])
            ->assertStatus(303)
            ->assertRedirect('http://localhost/contact#cms-form-quote-captcha');
    }

    public function test_with_no_provider_or_half_its_keys_forms_work_as_before(): void
    {
        $this->postJson('/api/forms/quote', ['email' => 'ada@example.com'])->assertOk();

        $this->provider('turnstile');
        $this->cms()->settingsRegistry()->save('forms', ['captcha_secret' => '']);
        $this->postJson('/api/forms/quote', ['email' => 'bea@example.com'])->assertOk();

        $this->assertNull(app(Captchas::class)->current());
        $this->assertSame([], $this->asked);
        $this->assertSame(2, Submission::query()->count());
        $this->assertStringNotContainsString('captcha', strtolower(app(FormRenderer::class)->render('quote')));
    }

    #[DataProvider('providers')]
    public function test_the_page_carries_the_widget_and_loads_its_script_once(string $name): void
    {
        [$field] = self::EXPECTED[$name];
        $this->provider($name);
        $foot = [];
        $this->cms()->hooks()->on('render.foot', static function (string $key, string $html) use (&$foot): void {
            $foot[$key] = $html;
        });

        $html = app(FormRenderer::class)->render('quote');
        $captcha = app(Captchas::class)->current();

        $this->assertStringContainsString('data-sitekey="site-key"', $html);
        $this->assertStringContainsString('data-captcha="'.$captcha->global().'"', $html);
        $this->assertStringContainsString('id="cms-form-quote-captcha"', $html);
        $this->assertStringContainsString('min-height:', $html);
        $this->assertStringNotContainsString('secret-key', $html);
        $this->assertSame('<script src="'.$captcha->script().'" async defer></script>', $foot['cms-forms-captcha']);
        $this->assertSame($field, $captcha->field());
    }

    public function test_the_secret_is_encrypted_and_never_shown_and_a_new_key_builds_the_site_again(): void
    {
        $signature = fn () => $this->cms()->hooks()->filter('site.build.signature', [])['forms-captcha'];
        $this->assertSame('none', $signature());

        $this->provider('hcaptcha');

        $this->assertSame('hcaptcha:site-key', $signature());
        $this->assertStringNotContainsString('secret-key', (string) file_get_contents($this->site.'/config/forms.yaml'));
        $this->assertStringNotContainsString('secret-key', (string) file_get_contents(storage_path('secrets.json')));
        $this->assertSame('••••••••', $this->cms()->settingsRegistry()->forDisplay('forms')['captcha_secret']);
    }

    public function test_the_canvas_shows_where_the_check_sits_and_loads_nothing(): void
    {
        $this->provider('turnstile');
        $foot = [];
        $this->cms()->hooks()->on('render.foot', static function (string $key) use (&$foot): void {
            $foot[] = $key;
        });
        $renderer = app(\Cms\Modules\Blocks\BlockRenderer::class);
        $renderer->editor = true;

        $html = $renderer->withEditorDocument('page', fn () => $renderer->render([['id' => 'embed', 'type' => 'forms/form', 'props' => ['form' => 'quote']]]));

        $this->assertStringContainsString('cms-placeholder', $html);
        $this->assertStringNotContainsString('cf-turnstile', $html);
        $this->assertNotContains('cms-forms-captcha', $foot);
    }
}

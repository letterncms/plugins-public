<?php

declare(strict_types=1);

namespace Cms\Brevo\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Cms\Abilities\AbilityContext;
use Cms\Brevo\BrevoTransport;
use Cms\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** The mail transport and the delivery stats, against a Brevo that Http::fake() plays. */
final class BrevoTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/brevo'];
    }

    protected function setUp(): void
    {
        // A development container may set the transport for every site: here the settings decide.
        putenv('CMS_EMAIL_TRANSPORT');
        unset($_ENV['CMS_EMAIL_TRANSPORT'], $_SERVER['CMS_EMAIL_TRANSPORT']);

        parent::setUp();
        Http::preventStrayRequests();
    }

    private function email(): Email
    {
        return (new Email)->from(new Address('hello@example.com', 'Harbour'))->to('ada@example.com')->replyTo('reply@example.com')
            ->subject('Welcome')->html('<p>Hi</p>')->text('Hi')->attach('file body', 'note.txt', 'text/plain');
    }

    public function test_the_transport_posts_the_message_to_brevo(): void
    {
        Http::fake(['api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<1@brevo>'], 201)]);
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-Cms-Tag', 'welcome');

        (new BrevoTransport('test-key'))->send($email);

        Http::assertSent(static function (Request $request) {
            $data = $request->data();

            return $request->method() === 'POST' && $request->url() === 'https://api.brevo.com/v3/smtp/email' && $request->hasHeader('api-key', 'test-key')
                && $data['sender'] === ['email' => 'hello@example.com', 'name' => 'Harbour'] && $data['to'] === [['email' => 'ada@example.com']]
                && $data['replyTo'] === ['email' => 'reply@example.com'] && $data['subject'] === 'Welcome' && $data['htmlContent'] === '<p>Hi</p>' && $data['textContent'] === 'Hi'
                && $data['tags'] === ['welcome'] && $data['attachment'] === [['name' => 'note.txt', 'content' => base64_encode('file body')]];
        });
    }

    public function test_the_transport_says_what_brevo_refused(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['code' => 'unauthorized', 'message' => 'Key not found'], 401)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Brevo: Key not found');
        (new BrevoTransport('wrong'))->send($this->email());
    }

    public function test_the_transport_sends_nothing_without_a_key(): void
    {
        Http::fake();

        try {
            (new BrevoTransport(''))->send($this->email());
            $this->fail('Sent without a key.');
        } catch (RuntimeException $e) {
            $this->assertSame('Brevo API key is missing.', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    private function stats(): array
    {
        return $this->cms()->abilities()->execute('brevo/stats', [], AbilityContext::system());
    }

    public function test_its_abilities_say_what_they_answer_with(): void
    {
        $mine = array_filter($this->cms()->abilities()->all(), static fn ($ability) => $ability->package === 'letterncms/brevo');
        $this->assertSame(['brevo/lists', 'brevo/stats', 'brevo/subscribe'], collect(array_keys($mine))->sort()->values()->all());

        foreach ($mine as $name => $ability) {
            $this->assertNotNull($ability->output(), "{$name} says what it answers with");
            // The two reads are named for what they answer with; subscribing is the one act.
            $this->assertSame($name !== 'brevo/subscribe', $ability->isReadOnly(), $name);
        }
    }

    public function test_stats_say_when_brevo_is_not_the_transport_or_has_no_key(): void
    {
        Http::fake();
        $this->assertSame(['state' => 'inactive'], $this->stats());

        $this->cms()->settingsRegistry()->save('email', ['transport' => 'brevo']);
        $this->assertSame(['state' => 'no-key'], $this->stats());
        Http::assertNothingSent();
    }

    public function test_stats_report_the_week_and_are_cached(): void
    {
        $this->cms()->settingsRegistry()->save('email', ['transport' => 'brevo', 'brevo_api_key' => 'test-key']);
        Http::fake(['api.brevo.com/v3/smtp/statistics/aggregatedReport*' => Http::response(['requests' => 120, 'delivered' => 110, 'uniqueOpens' => 60, 'uniqueClicks' => 12, 'hardBounces' => 3, 'softBounces' => 2, 'blocked' => 1, 'spamReports' => 0, 'invalid' => 4, 'unsubscribed' => 5])]);

        $stats = $this->stats();
        $this->stats();

        $this->assertSame('ok', $stats['state']);
        $this->assertSame([120, 110, 60, 12, 3, 2, 1, 0, 4, 5], [$stats['requests'], $stats['delivered'], $stats['opens'], $stats['clicks'], $stats['hardBounces'], $stats['softBounces'], $stats['blocked'], $stats['spamReports'], $stats['invalid'], $stats['unsubscribed']]);
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request) => $request->hasHeader('api-key', 'test-key') && str_contains($request->url(), 'days=7'));
    }

    public function test_stats_say_what_went_wrong(): void
    {
        $this->cms()->settingsRegistry()->save('email', ['transport' => 'brevo', 'brevo_api_key' => 'test-key']);

        Http::fake(['api.brevo.com/*' => Http::response(['message' => 'Key not found'], 401)]);
        $this->assertSame(['state' => 'error', 'message' => 'Brevo did not accept the API key.'], $this->stats());

        Cache::flush();
        Http::fake(['api.brevo.com/*' => Http::response('oops', 500)]);
        $this->assertSame('error', $this->stats()['state']);
    }

    public function test_the_key_is_kept_out_of_the_settings_file(): void
    {
        $this->cms()->settingsRegistry()->save('brevo', ['api_key' => 'list-key']);

        $this->assertStringNotContainsString('list-key', (string) @file_get_contents($this->site.'/config/brevo.yaml'));
        $this->assertSame('••••••••', $this->cms()->settingsRegistry()->forDisplay('brevo')['api_key']);

        $this->cms()->settingsRegistry()->save('email', ['transport' => 'brevo', 'brevo_api_key' => 'test-key']);
        $this->assertStringNotContainsString('test-key', (string) file_get_contents($this->site.'/config/email.yaml'));
    }

    public function test_every_change_has_a_past_tense_label_for_the_audit_log(): void
    {
        $past = $this->cms()->hooks()->filter('audit.past', []);
        $changes = array_filter($this->cms()->abilities()->all(), static fn ($ability) => $ability->package === 'letterncms/brevo' && ! $ability->isReadOnly());

        $this->assertNotSame([], $changes);

        foreach ($changes as $name => $ability) {
            $this->assertIsString($past[$name] ?? null, "{$name} has no past-tense label: add it to the audit.past filter.");
        }
    }
}

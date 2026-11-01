<?php

declare(strict_types=1);

namespace Cms\Seo\Tests;

use Cms\Seo\SiteFiles;
use Cms\Tests\TestCase;

final class SecurityTxtTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/seo'];
    }

    public function test_nothing_is_published_until_the_site_names_a_contact(): void
    {
        $this->assertNull($this->app->make(SiteFiles::class)->securityTxt());
        $this->get('/.well-known/security.txt')->assertNotFound();
    }

    public function test_an_email_or_a_page_becomes_a_contact_uri_with_an_expiry_a_year_out(): void
    {
        $this->cms()->settingsRegistry()->save('seo', ['security_contact' => 'security@example.com']);
        $this->get('/.well-known/security.txt')->assertOk()->assertSee('Contact: mailto:security@example.com');

        $this->cms()->settingsRegistry()->save('seo', ['security_contact' => '/security/report']);
        $text = (string) $this->app->make(SiteFiles::class)->securityTxt();

        $this->assertMatchesRegularExpression('#^Contact: https?://[^\n]+/security/report\nExpires: '.now()->addYear()->format('Y-m-d').'T[\d:]+Z\n$#', $text);
    }
}

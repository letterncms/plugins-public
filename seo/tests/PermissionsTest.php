<?php

declare(strict_types=1);

namespace Cms\Seo\Tests;

use Cms\Tests\TestCase;

final class PermissionsTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/seo'];
    }

    public function test_the_editor_role_a_site_starts_with_manages_seo(): void
    {
        $this->assertContains('seo.manage', $this->cms()->permissions()->forRole('editor'));
        $this->assertTrue($this->userWithRole('editor')->hasPermission('seo.manage'));
    }

    public function test_every_permission_names_a_default_role(): void
    {
        foreach ($this->cms()->permissions()->all() as $name => $permission) {
            if (str_starts_with($name, 'seo.')) {
                $this->assertNotSame([], $permission['roles'], $name);
            }
        }
    }

    public function test_every_change_has_a_past_tense_label_for_the_audit_log(): void
    {
        $past = $this->cms()->hooks()->filter('audit.past', []);
        $changes = array_filter($this->cms()->abilities()->all(), static fn ($ability) => $ability->package === 'letterncms/seo' && ! $ability->isReadOnly());

        $this->assertNotSame([], $changes);

        foreach ($changes as $name => $ability) {
            $this->assertIsString($past[$name] ?? null, "{$name} has no past-tense label: add it to the audit.past filter.");
        }
    }
}

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
}

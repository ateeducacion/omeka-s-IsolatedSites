<?php
declare(strict_types=1);

namespace IsolatedSitesTest\Module;

use IsolatedSites\Module;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Permissions\Assertion\IsSelfAssertion;
use PHPUnit\Framework\TestCase;

class ModuleAclTest extends TestCase
{
    public function testSiteEditorKeepsReadOnlyAccessToResourceTemplatesAndSelfScopedUserActions(): void
    {
        $module = new Module();
        $acl = new RecordingAcl();

        $siteAccessAssertion = $this->getMockBuilder(\IsolatedSites\Assertion\HasAccessToItemSiteAssertion::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['assert'])
            ->getMock();

        $serviceLocator = $this->createMock(ServiceLocatorInterface::class);
        $serviceLocator->expects($this->exactly(2))
            ->method('get')
            ->willReturnMap([
                ['Omeka\Acl', $acl],
                [\IsolatedSites\Assertion\HasAccessToItemSiteAssertion::class, $siteAccessAssertion],
            ]);

        $module->setServiceLocator($serviceLocator);

        $this->invokeAddAclRoleAndRules($module);

        $this->assertAclCallExists(
            $acl->denies,
            static function (array $call): bool {
                return $call['role'] === Module::ROLE_SITE_EDITOR
                    && $call['resource'] === [
                        \Omeka\Entity\ResourceTemplate::class,
                        \Omeka\Controller\Admin\ResourceTemplate::class,
                        \Omeka\Api\Adapter\ResourceTemplateAdapter::class,
                    ];
            },
            'Site editor should not inherit write access to resource templates.'
        );

        $this->assertAclCallExists(
            $acl->allows,
            static function (array $call): bool {
                return $call['role'] === Module::ROLE_SITE_EDITOR
                    && $call['resource'] === [\Omeka\Controller\Admin\ResourceTemplate::class]
                    && $call['privileges'] === ['index', 'browse', 'show', 'show-details', 'table-templates'];
            },
            'Site editor should retain read-only admin resource template privileges.'
        );

        $this->assertAclCallExists(
            $acl->allows,
            static function (array $call): bool {
                return $call['role'] === Module::ROLE_SITE_EDITOR
                    && $call['resource'] === [
                        \Omeka\Entity\ResourceTemplate::class,
                        \Omeka\Api\Adapter\ResourceTemplateAdapter::class,
                    ]
                    && $call['privileges'] === ['read'];
            },
            'Site editor should retain read privilege on resource template entities.'
        );

        $userEntityAllow = $this->assertAclCallExists(
            $acl->allows,
            static function (array $call): bool {
                return $call['role'] === Module::ROLE_SITE_EDITOR
                    && $call['resource'] === [\Omeka\Entity\User::class]
                    && $call['privileges'] === ['read', 'update', 'change-password']
                    && $call['assertion'] instanceof IsSelfAssertion;
            },
            'Site editor user entity permissions should be self-scoped.'
        );

        $userControllerAllow = $this->assertAclCallExists(
            $acl->allows,
            static function (array $call): bool {
                return $call['role'] === Module::ROLE_SITE_EDITOR
                    && $call['resource'] === [\Omeka\Controller\Admin\User::class]
                    && $call['privileges'] === ['show', 'edit']
                    && $call['assertion'] instanceof IsSelfAssertion;
            },
            'Site editor user controller permissions should be self-scoped.'
        );

        $this->assertSame(
            $userEntityAllow['assertion'],
            $userControllerAllow['assertion'],
            'Self assertion should be reused across user permissions.'
        );

        $this->assertAclCallExists(
            $acl->denies,
            static function (array $call): bool {
                return $call['role'] === Module::ROLE_SITE_EDITOR
                    && $call['resource'] === [\Omeka\Entity\User::class]
                    && $call['privileges'] === null;
            },
            'Site editor should not have blanket user entity access.'
        );

        $this->assertAclCallExists(
            $acl->denies,
            static function (array $call): bool {
                return $call['role'] === Module::ROLE_SITE_EDITOR
                    && $call['resource'] === [\Omeka\Controller\Admin\User::class]
                    && $call['privileges'] === ['browse'];
            },
            'Site editor should not browse all users.'
        );

        $this->assertAclCallExists(
            $acl->denies,
            static function (array $call): bool {
                return $call['role'] === Module::ROLE_SITE_EDITOR
                    && $call['resource'] === 'Omeka\Entity\Site'
                    && $call['privileges'] === 'create';
            },
            'Site editor should be prevented from creating sites.'
        );

        $this->assertAclCallExists(
            $acl->allows,
            static function (array $call): bool {
                return $call['role'] === null
                    && $call['resource'] === 'Omeka\Entity\Site'
                    && $call['privileges'] === 'update';
            },
            'Site updates should remain allowed for all roles when no role is specified.'
        );

        $this->assertAclCallExists(
            $acl->denies,
            static function (array $call): bool {
                return $call['role'] === Module::ROLE_SITE_EDITOR
                    && $call['resource'] === [\Omeka\Controller\SiteAdmin\Index::class]
                    && $call['privileges'] === ['index', 'edit', 'navigation', 'users', 'theme'];
            },
            'Site editor should not access restricted site admin actions.'
        );

        $this->assertAclCallExists(
            $acl->denies,
            static function (array $call): bool {
                return $call['role'] === Module::ROLE_SITE_EDITOR
                    && $call['resource'] === [\Omeka\Controller\Admin\SystemInfo::class];
            },
            'Site editor should not view system information.'
        );
    }

    private function invokeAddAclRoleAndRules(Module $module): void
    {
        $method = new \ReflectionMethod($module, 'addAclRoleAndRules');
        $method->setAccessible(true);
        $method->invoke($module);
    }

    /**
     * @param array<int, array{role:mixed,resource:mixed,privileges:mixed,assertion:mixed}> $calls
     */
    private function assertAclCallExists(array $calls, callable $predicate, string $message): array
    {
        foreach ($calls as $call) {
            if ($predicate($call)) {
                return $call;
            }
        }

        $this->fail($message);

        return [];
    }
}

class RecordingAcl
{
    /** @var array<int, array{role:mixed,resource:mixed,privileges:mixed,assertion:mixed}> */
    public array $denies = [];

    /** @var array<int, array{role:mixed,resource:mixed,privileges:mixed,assertion:mixed}> */
    public array $allows = [];

    public function addRole($role, $parent = null): void
    {
    }

    public function addRoleLabel($role, $label): void
    {
    }

    public function deny($role = null, $resource = null, $privileges = null, $assertion = null): void
    {
        $this->denies[] = [
            'role' => $role,
            'resource' => $resource,
            'privileges' => $privileges,
            'assertion' => $assertion,
        ];
    }

    public function allow($role = null, $resource = null, $privileges = null, $assertion = null): void
    {
        $this->allows[] = [
            'role' => $role,
            'resource' => $resource,
            'privileges' => $privileges,
            'assertion' => $assertion,
        ];
    }
}

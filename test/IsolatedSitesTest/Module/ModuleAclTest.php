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
        $acl->resources[\Log\Controller\Admin\LogController::class] = true;

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
                return in_array(Module::ROLE_SITE_EDITOR, (array) $call['role'], true)
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
                return in_array(Module::ROLE_SITE_EDITOR, (array) $call['role'], true)
                    && $call['resource'] === [\Omeka\Controller\Admin\ResourceTemplate::class]
                    && $call['privileges'] === ['index', 'browse', 'show', 'show-details', 'table-templates'];
            },
            'Site editor should retain read-only admin resource template privileges.'
        );

        $this->assertAclCallExists(
            $acl->allows,
            static function (array $call): bool {
                return in_array(Module::ROLE_SITE_EDITOR, (array) $call['role'], true)
                    && $call['resource'] === [
                        \Omeka\Entity\ResourceTemplate::class,
                        \Omeka\Api\Adapter\ResourceTemplateAdapter::class,
                    ]
                    && $call['privileges'] === ['read', 'search'];
            },
            'Site editor should retain read privilege on resource template entities.'
        );

        $userEntityAllow = $this->assertAclCallExists(
            $acl->allows,
            static function (array $call): bool {
                return in_array(Module::ROLE_SITE_EDITOR, (array) $call['role'], true)
                    && $call['resource'] === [\Omeka\Entity\User::class]
                    && $call['privileges'] === ['read', 'update', 'change-password']
                    && $call['assertion'] instanceof IsSelfAssertion;
            },
            'Site editor user entity permissions should be self-scoped.'
        );

        $userControllerAllow = $this->assertAclCallExists(
            $acl->allows,
            static function (array $call): bool {
                return in_array(Module::ROLE_SITE_EDITOR, (array) $call['role'], true)
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
                return in_array(Module::ROLE_SITE_EDITOR, (array) $call['role'], true)
                    && $call['resource'] === [\Omeka\Entity\User::class]
                    && $call['privileges'] === null;
            },
            'Site editor should not have blanket user entity access.'
        );

        $this->assertAclCallExists(
            $acl->denies,
            static function (array $call): bool {
                return in_array(Module::ROLE_SITE_EDITOR, (array) $call['role'], true)
                    && $call['resource'] === [\Omeka\Controller\Admin\User::class]
                    && $call['privileges'] === ['browse'];
            },
            'Site editor should not browse all users.'
        );

        $this->assertAclCallExists(
            $acl->denies,
            static function (array $call): bool {
                return in_array(Module::ROLE_SITE_EDITOR, (array) $call['role'], true)
                    && $call['resource'] === \Omeka\Entity\Site::class
                    && $call['privileges'] === ['create', 'delete'];
            },
            'Site editor should be prevented from creating sites.'
        );

        $this->assertAclCallExists(
            $acl->denies,
            static function (array $call): bool {
                $resource = (array) $call['resource'];
                $privileges = (array) $call['privileges'];
                return in_array(Module::ROLE_SITE_EDITOR, (array) $call['role'], true)
                    && in_array(\Omeka\Controller\SiteAdmin\Index::class, $resource, true)
                    && count(array_intersect(['index', 'edit', 'navigation', 'users', 'theme'], $privileges)) === 5;
            },
            'Site editor should not access restricted site admin actions.'
        );

        $this->assertAclCallExists(
            $acl->denies,
            static function (array $call): bool {
                return in_array(Module::ROLE_SITE_EDITOR, (array) $call['role'], true)
                    && $call['resource'] === [\Omeka\Controller\Admin\SystemInfo::class];
            },
            'Site editor should not view system information.'
        );
    }

    public function testLogAclRuleIsSkippedWhenLogResourceIsUnavailable(): void
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

        foreach ($acl->denies as $call) {
            $this->assertNotContains(
                \Log\Controller\Admin\LogController::class,
                (array) $call['resource'],
                'Log ACL rule should be skipped when the Log resource is not registered.'
            );
        }
    }

    public function testSiteManagerCanEditSiteButNotCreateOrManageSiteUsers(): void
    {
        $acl = $this->buildRecordingAcl();

        // Restricted SiteAdmin actions for site_manager are limited to creating/
        // deleting sites and managing site users — editing is allowed.
        $this->assertAclCallExists(
            $acl->denies,
            static function (array $call): bool {
                return in_array(Module::ROLE_SITE_MANAGER, (array) $call['role'], true)
                    && (array) $call['resource'] === [\Omeka\Controller\SiteAdmin\Index::class]
                    && $call['privileges'] === ['add', 'delete', 'users'];
            },
            'Site manager should only be blocked from creating/deleting sites and managing site users.'
        );

        // Site manager must NOT be denied page add/edit/delete (it can edit pages).
        $this->assertAclCallAbsent(
            $acl->denies,
            static function (array $call): bool {
                return in_array(Module::ROLE_SITE_MANAGER, (array) $call['role'], true)
                    && in_array(\Omeka\Controller\SiteAdmin\Page::class, (array) $call['resource'], true);
            },
            'Site manager should be able to manage pages (no page deny expected).'
        );

        // Site manager may create item sets and assets.
        $this->assertAclCallExists(
            $acl->allows,
            static function (array $call): bool {
                return in_array(Module::ROLE_SITE_MANAGER, (array) $call['role'], true)
                    && (array) $call['resource'] === [\Omeka\Entity\ItemSet::class, \Omeka\Entity\Asset::class]
                    && $call['privileges'] === ['create'];
            },
            'Site manager should be able to create item sets and assets.'
        );
    }

    public function testSiteResearcherIsReadOnly(): void
    {
        $acl = $this->buildRecordingAcl();

        // No write privileges on items/media.
        $this->assertAclCallExists(
            $acl->denies,
            static function (array $call): bool {
                return in_array(Module::ROLE_SITE_RESEARCHER, (array) $call['role'], true)
                    && (array) $call['resource'] === [\Omeka\Entity\Item::class, \Omeka\Entity\Media::class]
                    && $call['privileges'] === ['create', 'update', 'delete', 'edit'];
            },
            'Site researcher should not be able to write items or media.'
        );

        // Cannot create item sets / assets.
        $this->assertAclCallExists(
            $acl->denies,
            static function (array $call): bool {
                return in_array(Module::ROLE_SITE_RESEARCHER, (array) $call['role'], true)
                    && (array) $call['resource'] === [\Omeka\Entity\ItemSet::class, \Omeka\Entity\Asset::class]
                    && $call['privileges'] === ['create', 'update', 'delete'];
            },
            'Site researcher should not create item sets or assets.'
        );

        // Cannot edit the site (shares the no-site-edit page deny with site_editor).
        $this->assertAclCallExists(
            $acl->denies,
            static function (array $call): bool {
                return in_array(Module::ROLE_SITE_RESEARCHER, (array) $call['role'], true)
                    && (array) $call['resource'] === [\Omeka\Controller\SiteAdmin\Page::class]
                    && $call['privileges'] === ['add', 'edit', 'delete'];
            },
            'Site researcher should not be able to add/edit/delete pages.'
        );
    }

    private function buildRecordingAcl(): RecordingAcl
    {
        $module = new Module();
        $acl = new RecordingAcl();
        $acl->resources[\Log\Controller\Admin\LogController::class] = true;

        $siteAccessAssertion = $this->getMockBuilder(\IsolatedSites\Assertion\HasAccessToItemSiteAssertion::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['assert'])
            ->getMock();

        $serviceLocator = $this->createMock(ServiceLocatorInterface::class);
        $serviceLocator->method('get')
            ->willReturnMap([
                ['Omeka\Acl', $acl],
                [\IsolatedSites\Assertion\HasAccessToItemSiteAssertion::class, $siteAccessAssertion],
            ]);

        $module->setServiceLocator($serviceLocator);
        $this->invokeAddAclRoleAndRules($module);

        return $acl;
    }

    private function invokeAddAclRoleAndRules(Module $module): void
    {
        $method = new \ReflectionMethod($module, 'addAclRoleAndRules');
        // Note: setAccessible() has no effect since PHP 8.1, removed to avoid deprecation warning
        $method->invoke($module);
    }

    /**
     * @param array<int, array{role:mixed,resource:mixed,privileges:mixed,assertion:mixed}> $calls
     */
    private function assertAclCallAbsent(array $calls, callable $predicate, string $message): void
    {
        foreach ($calls as $call) {
            if ($predicate($call)) {
                $this->fail($message);
            }
        }

        $this->addToAssertionCount(1);
    }

    /**
     * @param array<int, array{role:mixed,resource:mixed,privileges:mixed,assertion:mixed}> $calls
     */
    private function assertAclCallExists(array $calls, callable $predicate, string $message): array
    {
        foreach ($calls as $call) {
            if ($predicate($call)) {
                $this->addToAssertionCount(1);
                return $call;
            }
        }

        $this->fail($message);

        return [];
    }
}

class RecordingAcl
{
    /** @var array<string, bool> */
    public array $resources = [];

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

    public function hasResource($resource): bool
    {
        return !empty($this->resources[$resource]);
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

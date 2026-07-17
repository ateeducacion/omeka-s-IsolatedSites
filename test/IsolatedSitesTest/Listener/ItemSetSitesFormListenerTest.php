<?php
namespace IsolatedSitesTest\Listener;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use IsolatedSites\Listener\ItemSetSitesFormListener;
use IsolatedSites\Service\GrantedSites;
use Laminas\Authentication\AuthenticationService;
use Laminas\EventManager\Event;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Entity\Site;
use Omeka\Entity\User;
use Omeka\Settings\UserSettings;
use PHPUnit\Framework\TestCase;

class ItemSetSitesFormListenerTest extends TestCase
{
    private $auth;
    private $grantedSites;
    private $entityManager;
    private $userSettings;
    private $repository;

    protected function setUp(): void
    {
        $this->auth = $this->createMock(AuthenticationService::class);
        $this->grantedSites = $this->createMock(GrantedSites::class);
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->userSettings = $this->createMock(UserSettings::class);
        $this->repository = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')->willReturn($this->repository);
    }

    /**
     * Builds a Site with an id. Core has no id setter, and $id is protected, so
     * a subclass reaches it without ReflectionProperty::setAccessible(), which
     * is deprecated on PHP 8.5 but still required below 8.1 — this module
     * supports both.
     */
    private function makeSite(int $id, string $title): Site
    {
        $site = new class extends Site {
            public function setIdForTest(int $id): void
            {
                $this->id = $id;
            }
        };
        $site->setIdForTest($id);
        $site->setTitle($title);

        return $site;
    }

    private function makeListener(
        string $role,
        array $granted,
        array $sites = [],
        array $defaultItemSites = []
    ): ItemSetSitesFormListener {
        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn($role);
        $user->method('getId')->willReturn(7);
        $this->auth->method('getIdentity')->willReturn($user);
        $this->grantedSites->method('forUser')->willReturn($granted);
        $this->repository->method('findBy')->willReturn($sites);
        $this->userSettings->method('get')->willReturn($defaultItemSites);

        return new ItemSetSitesFormListener(
            $this->auth,
            $this->grantedSites,
            $this->entityManager,
            $this->userSettings
        );
    }

    private function sectionNavEvent(): Event
    {
        $params = new \ArrayObject([
            'section_nav' => ['resource-values' => 'Values', 'advanced-settings' => 'Advanced'],
            'resource' => null,
        ]);

        return new Event('view.add.section_nav', null, $params);
    }

    public function testAddsSectionNavForSiteEditor()
    {
        $listener = $this->makeListener('site_editor', [1]);
        $event = $this->sectionNavEvent();

        $listener->addSectionNav($event);

        $sectionNav = $event->getParam('section_nav');
        $this->assertArrayHasKey(ItemSetSitesFormListener::SECTION_ID, $sectionNav);
        $this->assertSame('Sites', $sectionNav[ItemSetSitesFormListener::SECTION_ID]);
    }

    public function testAddsSectionNavForSiteManager()
    {
        $listener = $this->makeListener('site_manager', [1]);
        $event = $this->sectionNavEvent();

        $listener->addSectionNav($event);

        $this->assertArrayHasKey(
            ItemSetSitesFormListener::SECTION_ID,
            $event->getParam('section_nav')
        );
    }

    public function testDoesNotAddSectionNavForGlobalAdmin()
    {
        $listener = $this->makeListener('global_admin', [1]);
        $event = $this->sectionNavEvent();

        $listener->addSectionNav($event);

        $this->assertArrayNotHasKey(
            ItemSetSitesFormListener::SECTION_ID,
            $event->getParam('section_nav')
        );
    }

    public function testDoesNotAddSectionNavForSiteResearcher()
    {
        $listener = $this->makeListener('site_researcher', [1]);
        $event = $this->sectionNavEvent();

        $listener->addSectionNav($event);

        $this->assertArrayNotHasKey(
            ItemSetSitesFormListener::SECTION_ID,
            $event->getParam('section_nav')
        );
    }

    public function testPreservesExistingSectionNavEntries()
    {
        $listener = $this->makeListener('site_editor', [1]);
        $event = $this->sectionNavEvent();

        $listener->addSectionNav($event);

        $sectionNav = $event->getParam('section_nav');
        $this->assertSame('Values', $sectionNav['resource-values']);
        $this->assertSame('Advanced', $sectionNav['advanced-settings']);
    }

    public function testOptionsAreScopedToGrantedSites()
    {
        $listener = $this->makeListener('site_editor', [1], [$this->makeSite(1, 'Site A')]);

        $options = $listener->buildSiteOptions();

        $this->assertCount(1, $options);
        $this->assertSame(1, $options[0]['id']);
        $this->assertSame('Site A', $options[0]['title']);
    }

    public function testCreatePrefillsFromDefaultItemSitesIntersectedWithGranted()
    {
        $listener = $this->makeListener(
            'site_editor',
            [1, 2],
            [$this->makeSite(1, 'Site A'), $this->makeSite(2, 'Site B')],
            [2, 99] // 99 is not granted and must not leak in
        );

        $options = $listener->buildSiteOptions();

        $byId = array_column($options, 'selected', 'id');
        $this->assertFalse($byId[1]);
        $this->assertTrue($byId[2]);
    }

    public function testEditPrefillsFromExistingAssignments()
    {
        $siteRep = $this->createMock(SiteRepresentation::class);
        $itemSet = $this->createMock(ItemSetRepresentation::class);
        // ItemSetRepresentation::sites() is keyed by site id.
        $itemSet->method('sites')->willReturn([2 => $siteRep]);

        $listener = $this->makeListener(
            'site_editor',
            [1, 2],
            [$this->makeSite(1, 'Site A'), $this->makeSite(2, 'Site B')]
        );

        $options = $listener->buildSiteOptions($itemSet);

        $byId = array_column($options, 'selected', 'id');
        $this->assertFalse($byId[1]);
        $this->assertTrue($byId[2]);
    }

    public function testReturnsNoOptionsWhenUserHasNoGrantedSites()
    {
        $listener = $this->makeListener('site_editor', []);

        $this->assertSame([], $listener->buildSiteOptions());
    }

    public function testOptionsStayScopedWhenLimitToGrantedSitesIsDisabled()
    {
        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn('site_editor');
        $user->method('getId')->willReturn(7);
        $this->auth->method('getIdentity')->willReturn($user);
        $this->grantedSites->method('forUser')->willReturn([1]);
        $this->repository->method('findBy')->willReturn([$this->makeSite(1, 'Site A')]);

        // limit_to_granted_sites governs read-side visibility only. Widening the
        // options when it is off would let a Site Editor assign an item set to a
        // site they hold no permission on.
        $this->userSettings->method('get')
            ->willReturnCallback(function ($id, $default = null) {
                return $id === 'limit_to_granted_sites' ? 0 : [];
            });

        $listener = new ItemSetSitesFormListener(
            $this->auth,
            $this->grantedSites,
            $this->entityManager,
            $this->userSettings
        );

        $options = $listener->buildSiteOptions();

        $this->assertCount(1, $options);
        $this->assertSame(1, $options[0]['id']);
    }
}

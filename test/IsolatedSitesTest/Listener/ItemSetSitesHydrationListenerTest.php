<?php
namespace IsolatedSitesTest\Listener;

use Doctrine\ORM\EntityManager;
use IsolatedSites\Listener\ItemSetSitesHydrationListener;
use IsolatedSites\Service\GrantedSites;
use Laminas\Authentication\AuthenticationService;
use Laminas\EventManager\Event;
use Omeka\Api\Request;
use Omeka\Entity\ItemSet;
use Omeka\Entity\Site;
use Omeka\Entity\SiteItemSet;
use Omeka\Entity\User;
use PHPUnit\Framework\TestCase;

class ItemSetSitesHydrationListenerTest extends TestCase
{
    private $auth;
    private $grantedSites;
    private $entityManager;
    private $persisted = [];
    private $removed = [];
    private $sites = [];

    protected function setUp(): void
    {
        $this->persisted = [];
        $this->removed = [];
        $this->sites = [];

        $this->auth = $this->createMock(AuthenticationService::class);
        $this->grantedSites = $this->createMock(GrantedSites::class);
        $this->entityManager = $this->createMock(EntityManager::class);

        $this->entityManager->method('persist')
            ->willReturnCallback(function ($entity) { $this->persisted[] = $entity; });
        $this->entityManager->method('remove')
            ->willReturnCallback(function ($entity) { $this->removed[] = $entity; });
        $this->entityManager->method('find')
            ->willReturnCallback(function ($class, $id) { return $this->sites[$id] ?? null; });
    }

    /**
     * Builds a Site with an id. Core has no id setter, and $id is protected, so
     * a subclass reaches it without ReflectionProperty::setAccessible(), which
     * is deprecated on PHP 8.5 but still required below 8.1 — this module
     * supports both.
     */
    private function makeSite(int $id, string $title = 'Site'): Site
    {
        $site = new class extends Site {
            public function setIdForTest(int $id): void
            {
                $this->id = $id;
            }
        };
        $site->setIdForTest($id);
        $site->setTitle($title);
        $this->sites[$id] = $site;

        return $site;
    }

    private function makeRow(Site $site, ItemSet $itemSet, int $position = 1): SiteItemSet
    {
        $row = new SiteItemSet();
        $row->setSite($site);
        $row->setItemSet($itemSet);
        $row->setPosition($position);
        $site->getSiteItemSets()->add($row);
        $itemSet->getSiteItemSets()->add($row);

        return $row;
    }

    private function makeListener(string $role, array $granted): ItemSetSitesHydrationListener
    {
        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn($role);
        $user->method('getId')->willReturn(7);
        $this->auth->method('getIdentity')->willReturn($user);
        $this->grantedSites->method('forUser')->willReturn($granted);

        return new ItemSetSitesHydrationListener(
            $this->auth,
            $this->grantedSites,
            $this->entityManager
        );
    }

    private function makeEvent(ItemSet $itemSet, array $content): Event
    {
        $request = new Request(Request::UPDATE, 'item_sets');
        $request->setContent($content);

        return new Event('api.hydrate.post', null, [
            'entity' => $itemSet,
            'request' => $request,
        ]);
    }

    public function testPersistsRowForNewlySelectedGrantedSite()
    {
        $itemSet = new ItemSet();
        $this->makeSite(1, 'Site A');
        $listener = $this->makeListener('site_editor', [1]);

        $listener($this->makeEvent($itemSet, ['o:site' => [1]]));

        $this->assertCount(1, $this->persisted);
        $this->assertInstanceOf(SiteItemSet::class, $this->persisted[0]);
        $this->assertSame(1, $this->persisted[0]->getSite()->getId());
        $this->assertSame($itemSet, $this->persisted[0]->getItemSet());
        $this->assertSame([], $this->removed);
    }

    public function testRemovesRowForDeselectedGrantedSite()
    {
        $itemSet = new ItemSet();
        $siteA = $this->makeSite(1, 'Site A');
        $row = $this->makeRow($siteA, $itemSet);
        $listener = $this->makeListener('site_editor', [1]);

        $listener($this->makeEvent($itemSet, ['o:site' => []]));

        $this->assertSame([$row], $this->removed);
        $this->assertSame([], $this->persisted);
    }

    public function testPreservesRowForSiteTheUserCannotManage()
    {
        $itemSet = new ItemSet();
        $siteA = $this->makeSite(1, 'Site A');
        $siteB = $this->makeSite(2, 'Site B');
        $this->makeRow($siteA, $itemSet);
        $hiddenRow = $this->makeRow($siteB, $itemSet);
        $listener = $this->makeListener('site_editor', [1]);

        // User deselects everything they can see. Site B must survive.
        $listener($this->makeEvent($itemSet, ['o:site' => []]));

        $this->assertNotContains($hiddenRow, $this->removed);
        $this->assertCount(1, $this->removed);
    }

    public function testDropsSubmittedSiteTheUserHasNoPermissionOn()
    {
        $itemSet = new ItemSet();
        $this->makeSite(1, 'Site A');
        $this->makeSite(2, 'Site B');
        $listener = $this->makeListener('site_editor', [1]);

        $listener($this->makeEvent($itemSet, ['o:site' => [1, 2]]));

        $this->assertCount(1, $this->persisted);
        $this->assertSame(1, $this->persisted[0]->getSite()->getId());
    }

    public function testNewRowPositionAppendsAfterExistingRowsOfThatSite()
    {
        $otherItemSet = new ItemSet();
        $itemSet = new ItemSet();
        $siteA = $this->makeSite(1, 'Site A');
        $this->makeRow($siteA, $otherItemSet, 4);
        $listener = $this->makeListener('site_editor', [1]);

        $listener($this->makeEvent($itemSet, ['o:site' => [1]]));

        $this->assertSame(5, $this->persisted[0]->getPosition());
    }

    public function testNoOpsForGlobalAdmin()
    {
        $itemSet = new ItemSet();
        $this->makeSite(1, 'Site A');
        $listener = $this->makeListener('global_admin', [1]);

        $listener($this->makeEvent($itemSet, ['o:site' => [1]]));

        $this->assertSame([], $this->persisted);
        $this->assertSame([], $this->removed);
    }

    public function testNoOpsForCoreEditor()
    {
        $itemSet = new ItemSet();
        $this->makeSite(1, 'Site A');
        $listener = $this->makeListener('editor', [1]);

        $listener($this->makeEvent($itemSet, ['o:site' => [1]]));

        $this->assertSame([], $this->persisted);
    }

    public function testNoOpsWhenSiteKeyIsAbsent()
    {
        $itemSet = new ItemSet();
        $siteA = $this->makeSite(1, 'Site A');
        $this->makeRow($siteA, $itemSet);
        $listener = $this->makeListener('site_editor', [1]);

        $listener($this->makeEvent($itemSet, ['o:is_public' => 1]));

        $this->assertSame([], $this->removed);
        $this->assertSame([], $this->persisted);
    }

    public function testAcceptsSiteManager()
    {
        $itemSet = new ItemSet();
        $this->makeSite(1, 'Site A');
        $listener = $this->makeListener('site_manager', [1]);

        $listener($this->makeEvent($itemSet, ['o:site' => [1]]));

        $this->assertCount(1, $this->persisted);
    }

    public function testIgnoresBlankValuesFromTheHiddenFormInput()
    {
        $itemSet = new ItemSet();
        $this->makeSite(1, 'Site A');
        $listener = $this->makeListener('site_editor', [1]);

        $listener($this->makeEvent($itemSet, ['o:site' => ['', '1']]));

        $this->assertCount(1, $this->persisted);
        $this->assertSame(1, $this->persisted[0]->getSite()->getId());
    }

    public function testDoesNotDuplicateAnAlreadyAssignedSite()
    {
        $itemSet = new ItemSet();
        $siteA = $this->makeSite(1, 'Site A');
        $this->makeRow($siteA, $itemSet);
        $listener = $this->makeListener('site_editor', [1]);

        $listener($this->makeEvent($itemSet, ['o:site' => [1]]));

        $this->assertSame([], $this->persisted);
        $this->assertSame([], $this->removed);
    }
}

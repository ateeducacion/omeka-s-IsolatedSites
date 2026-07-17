# Item-Set Site Assignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users with the `site_editor` and `site_manager` roles assign an item set to their granted sites directly from the item-set add/edit form.

**Architecture:** Two listeners plus one shared service, following this module's existing one-listener-per-concern shape. A form listener injects a **Sites** tab and fieldset into core's item-set form via view events; a hydration listener reconciles `SiteItemSet` rows on `api.hydrate.post`. No ACL rules change.

**Tech Stack:** PHP 7.4+, Omeka S 4.x module API, Laminas EventManager, Doctrine ORM/DBAL 2.x-3.x, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-07-17-item-set-site-assignment-design.md`
**Issue:** [#32](https://github.com/ateeducacion/omeka-s-IsolatedSites/issues/32)

## Global Constraints

- **Never reconcile through the `ItemSet::$siteItemSets` collection.** Core maps it `@OneToMany(targetEntity="SiteItemSet", mappedBy="itemSet")` with **no cascade and no orphanRemoval** (`ItemSet.php:28-31`), unlike the Site side which has `cascade={"persist","remove"}, orphanRemoval=true` (`Site.php:108-117`). `add()`/`removeElement()` from the ItemSet side silently no-op. Always use `EntityManager::persist()` / `EntityManager::remove()` on `SiteItemSet`.
- **Only `site_editor` and `site_manager` are affected.** Every other role — `global_admin`, `site_admin`, core `editor`, `site_researcher` — must see byte-identical behaviour. Reference the roles as `\IsolatedSites\Module::ROLE_SITE_EDITOR` and `::ROLE_SITE_MANAGER`; never hardcode the strings.
- **Preserve, invisible.** Rows for sites the user holds no `site_permission` on are never shown, never removed, never counted in the UI.
- **Absence means "do not touch."** If `o:site` is not a key in the API request content, skip reconciliation entirely. It must never be read as "unassign everything."
- **Neither module flag gates this feature.** `activate_IsolatedSites` and `limit_to_granted_sites` govern read-side visibility only. Attach these listeners **outside** the `if ($settings->get('activate_IsolatedSites', true))` block in `Module::attachListeners`.
- **Run tests with:** `make test` (wraps `vendor/bin/phpunit -c test/phpunit.xml --colors=always --testdox`).
- Declare `declare(strict_types=1);` in new `src/` files, matching `Module.php` and `config/module.config.php`.

## File Structure

| File | Responsibility |
|---|---|
| `src/Service/GrantedSites.php` | *(create)* Resolves site ids a user holds a `site_permission` row for |
| `src/Listener/ItemSetSitesHydrationListener.php` | *(create)* Reconciles `SiteItemSet` rows on save |
| `src/Listener/ItemSetSitesFormListener.php` | *(create)* Adds the Sites tab; builds options; echoes the fieldset |
| `view/isolated-sites/item-set-sites-fieldset.phtml` | *(create)* Fieldset markup |
| `config/module.config.php` | *(modify)* Factories for the three new services |
| `Module.php` | *(modify)* Attach the five new event listeners |
| `test/IsolatedSitesTest/Listener/*` | *(create)* Unit tests |
| `README.md` | *(modify)* Role matrix |

## Verified Core Contracts

These were checked against `omeka/omeka-s@develop`. Do not re-derive them.

| Contract | Detail |
|---|---|
| `view.{add,edit}.section_nav` | Triggered with `$filter = true` (`SectionNav.php:27`). Params are an `ArrayObject`; mutate `section_nav` and `setParam` it back. |
| `view.{add,edit}.form.after` | Triggered with `$filter = false` (default, `Trigger.php:44`). The listener **must `echo`**; a return value is discarded. |
| Event target in view events | The view/`PhpRenderer` — `new Event($name, $this->getView(), $params)` (`Trigger.php:59`). |
| Attach identifier for view events | `$routeMatch->getParam('controller')` → `'Omeka\Controller\Admin\ItemSet'`. |
| `api.hydrate.post` | Params `entity`, `request`, `errorStore` (`AbstractEntityAdapter.php:655-661`). Fires inside the adapter's transaction, before flush. |
| POST → API passthrough | `ItemSetController::addAction`/`editAction` forward raw POST to the API, so `o:site[]` reaches the adapter request content. |
| `ItemSetRepresentation::sites()` | Returns `SiteRepresentation[]` **keyed by site id**. |
| `SiteItemSet` | `setSite(Site)`, `setItemSet(ItemSet)`, `setPosition($int)`, `getSite()`, `getPosition()`. Unique constraint on `(site_id, item_set_id)`. |
| `default_item_sites` | Core user setting holding site ids, used for new items (`UserForm.php:190`). |

---

### Task 1: `GrantedSites` service

The query `SELECT site_id FROM site_permission WHERE user_id = :id` is duplicated across existing listeners. Tasks 2 and 3 both need it, so extract it once. **Do not migrate the existing listeners** — that is explicitly out of scope.

**Files:**
- Create: `src/Service/GrantedSites.php`
- Create: `test/IsolatedSitesTest/Listener/GrantedSitesTest.php`
- Modify: `config/module.config.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `IsolatedSites\Service\GrantedSites::forUser(int $userId): array` — returns `int[]` of site ids, possibly empty. Registered in the service manager under its own FQCN.

- [ ] **Step 1: Write the failing test**

Create `test/IsolatedSitesTest/Listener/GrantedSitesTest.php`:

```php
<?php
namespace IsolatedSitesTest\Listener;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use IsolatedSites\Service\GrantedSites;
use PHPUnit\Framework\TestCase;

class GrantedSitesTest extends TestCase
{
    private function serviceReturning(array $rows, &$captured = null): GrantedSites
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn($rows);

        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')
            ->willReturnCallback(function ($sql, $params) use ($result, &$captured) {
                $captured = ['sql' => $sql, 'params' => $params];
                return $result;
            });

        return new GrantedSites($connection);
    }

    public function testReturnsSiteIdsAsIntegers()
    {
        $service = $this->serviceReturning(['1', '3']);

        $this->assertSame([1, 3], $service->forUser(7));
    }

    public function testReturnsEmptyArrayForUserWithoutPermissions()
    {
        $service = $this->serviceReturning([]);

        $this->assertSame([], $service->forUser(7));
    }

    public function testQueriesSitePermissionForTheGivenUser()
    {
        $captured = null;
        $service = $this->serviceReturning(['1'], $captured);

        $service->forUser(7);

        $this->assertStringContainsString('site_permission', $captured['sql']);
        $this->assertSame(['user_id' => 7], $captured['params']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter GrantedSitesTest`
Expected: FAIL — `Class "IsolatedSites\Service\GrantedSites" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `src/Service/GrantedSites.php`:

```php
<?php
declare(strict_types=1);

namespace IsolatedSites\Service;

use Doctrine\DBAL\Connection;

/**
 * Resolves the sites a user holds an explicit permission for.
 *
 * Site permissions — not the limit_to_granted_sites flag — are the authority on
 * which sites a user may manage content in.
 */
class GrantedSites
{
    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return int[] Site ids the user has a site_permission row for.
     */
    public function forUser(int $userId): array
    {
        $sql = 'SELECT DISTINCT site_id FROM site_permission WHERE user_id = :user_id';
        $ids = $this->connection
            ->executeQuery($sql, ['user_id' => $userId])
            ->fetchFirstColumn();

        return array_map('intval', $ids);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter GrantedSitesTest`
Expected: PASS — 3 tests, 3 assertions or more.

- [ ] **Step 5: Register the service**

In `config/module.config.php`, add to the `use` statements near the top:

```php
use IsolatedSites\Service\GrantedSites;
```

Then add this entry inside `'service_manager' => ['factories' => [ ... ]]`, after the `UserSettingsValidationListener::class` factory:

```php
            GrantedSites::class => function ($services) {
                return new GrantedSites(
                    $services->get('Omeka\Connection')
                );
            },
```

- [ ] **Step 6: Run the full suite**

Run: `make test`
Expected: PASS — no regressions in existing tests.

- [ ] **Step 7: Commit**

```bash
git add src/Service/GrantedSites.php test/IsolatedSitesTest/Listener/GrantedSitesTest.php config/module.config.php
git commit -m "feat(sites): add GrantedSites service

Resolves the site ids a user holds a site_permission row for. Extracted
because item-set site assignment needs it in both the form listener and
the hydration listener.

Refs #32"
```

---

### Task 2: `ItemSetSitesHydrationListener`

The load-bearing task. Reconciles `SiteItemSet` rows when an item set is saved.

**Files:**
- Create: `src/Listener/ItemSetSitesHydrationListener.php`
- Create: `test/IsolatedSitesTest/Listener/ItemSetSitesHydrationListenerTest.php`
- Modify: `config/module.config.php`
- Modify: `Module.php`

**Interfaces:**
- Consumes: `GrantedSites::forUser(int): array` from Task 1.
- Produces: `IsolatedSites\Listener\ItemSetSitesHydrationListener::__invoke(EventInterface $event): void`, constructed as `new ItemSetSitesHydrationListener(AuthenticationService $auth, GrantedSites $grantedSites, EntityManager $entityManager)` — that constructor order is relied on by the config factory.

- [ ] **Step 1: Write the failing test**

Create `test/IsolatedSitesTest/Listener/ItemSetSitesHydrationListenerTest.php`:

```php
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

    /** Builds a Site whose id is set via reflection (no public setter in core). */
    private function makeSite(int $id, string $title = 'Site'): Site
    {
        $site = new Site();
        $reflection = new \ReflectionProperty(Site::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($site, $id);
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter ItemSetSitesHydrationListenerTest`
Expected: FAIL — `Class "IsolatedSites\Listener\ItemSetSitesHydrationListener" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `src/Listener/ItemSetSitesHydrationListener.php`:

```php
<?php
declare(strict_types=1);

namespace IsolatedSites\Listener;

use Doctrine\ORM\EntityManager;
use IsolatedSites\Module;
use IsolatedSites\Service\GrantedSites;
use Laminas\Authentication\AuthenticationService;
use Laminas\EventManager\EventInterface;
use Omeka\Api\Request;
use Omeka\Entity\ItemSet;
use Omeka\Entity\Site;
use Omeka\Entity\SiteItemSet;

/**
 * Reconciles an item set's site assignments for site-scoped roles.
 *
 * Core exposes site assignment only on Site > Resources, which site_editor may
 * not reach, so this listener consumes the o:site[] field that
 * ItemSetSitesFormListener adds to the item-set form.
 */
class ItemSetSitesHydrationListener
{
    /**
     * Roles whose item-set site assignments this listener manages. Every other
     * role keeps core behaviour, so o:site is ignored for them.
     */
    private const MANAGED_ROLES = [
        Module::ROLE_SITE_EDITOR,
        Module::ROLE_SITE_MANAGER,
    ];

    private $auth;
    private $grantedSites;
    private $entityManager;

    public function __construct(
        AuthenticationService $auth,
        GrantedSites $grantedSites,
        EntityManager $entityManager
    ) {
        $this->auth = $auth;
        $this->grantedSites = $grantedSites;
        $this->entityManager = $entityManager;
    }

    public function __invoke(EventInterface $event): void
    {
        $user = $this->auth->getIdentity();
        if (!$user || !in_array($user->getRole(), self::MANAGED_ROLES, true)) {
            return;
        }

        $entity = $event->getParam('entity');
        $request = $event->getParam('request');
        if (!$entity instanceof ItemSet || !$request instanceof Request) {
            return;
        }

        $content = $request->getContent();
        // An absent key means "leave assignments alone" (e.g. a partial REST
        // update). Only an explicitly submitted, empty list unassigns.
        if (!array_key_exists('o:site', $content)) {
            return;
        }

        $granted = $this->grantedSites->forUser((int) $user->getId());
        $submitted = array_values(array_intersect(
            $this->normalizeIds($content['o:site']),
            $granted
        ));

        $existing = [];
        foreach ($entity->getSiteItemSets() as $row) {
            $site = $row->getSite();
            if ($site) {
                $existing[(int) $site->getId()] = $row;
            }
        }

        // Unassign only what the user can see. Rows for sites outside their
        // permissions are left untouched and never surfaced.
        foreach ($existing as $siteId => $row) {
            if (in_array($siteId, $granted, true) && !in_array($siteId, $submitted, true)) {
                $this->entityManager->remove($row);
            }
        }

        foreach ($submitted as $siteId) {
            if (isset($existing[$siteId])) {
                continue;
            }
            $site = $this->entityManager->find(Site::class, $siteId);
            if (!$site) {
                continue;
            }
            // ItemSet::$siteItemSets has no cascade, so the row must be
            // persisted explicitly; adding to the collection would no-op.
            $row = new SiteItemSet();
            $row->setSite($site);
            $row->setItemSet($entity);
            $row->setPosition($this->nextPosition($site));
            $this->entityManager->persist($row);
        }
    }

    /**
     * Accepts both the form's scalar ids and the REST shape [{'o:id': 1}].
     *
     * @param mixed $value
     * @return int[]
     */
    private function normalizeIds($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['o:id'] ?? null;
            }
            if (is_numeric($item)) {
                $ids[] = (int) $item;
            }
        }

        return array_values(array_unique($ids));
    }

    private function nextPosition(Site $site): int
    {
        $max = 0;
        foreach ($site->getSiteItemSets() as $row) {
            $max = max($max, (int) $row->getPosition());
        }

        return $max + 1;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter ItemSetSitesHydrationListenerTest`
Expected: PASS — 11 tests.

- [ ] **Step 5: Register the service**

In `config/module.config.php`, add to the `use` statements:

```php
use IsolatedSites\Listener\ItemSetSitesHydrationListener;
```

Add this factory inside `'service_manager' => ['factories' => [ ... ]]`, after the `GrantedSites::class` factory:

```php
            ItemSetSitesHydrationListener::class => function ($services) {
                return new ItemSetSitesHydrationListener(
                    $services->get('Omeka\AuthenticationService'),
                    $services->get(GrantedSites::class),
                    $services->get('Omeka\EntityManager')
                );
            },
```

- [ ] **Step 6: Attach the listener**

In `Module.php`, add to the `use` statements after the other listener imports (near line 21):

```php
use IsolatedSites\Listener\ItemSetSitesHydrationListener;
```

In `attachListeners`, add this **after** the closing brace of the `if ($settings->get('activate_IsolatedSites', true)) { ... }` block and before the `// API listeners for custom user settings` comment. It sits outside that block deliberately: the kill switch governs read-side filtering, and disabling it must not remove a Site Editor's ability to assign their own item sets.

```php
        // Item-set site assignment for site-scoped roles. Deliberately outside
        // the activate_IsolatedSites switch: that flag governs read-side
        // filtering, not the ability to place an item set in your own site.
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemSetAdapter',
            'api.hydrate.post',
            [$this->serviceLocator->get(ItemSetSitesHydrationListener::class), '__invoke']
        );
```

- [ ] **Step 7: Update `ModuleTest` for the new attachment**

`test/IsolatedSitesTest/Module/ModuleTest.php` asserts **exact** call counts and ordering, so adding a listener breaks it. This is intentional — the disabled-flag test is what proves the new listener survives the kill switch, which is a spec requirement.

Add the import near the other listener imports at the top of the file:

```php
use IsolatedSites\Listener\ItemSetSitesHydrationListener;
```

In **`testAttachListeners`** (the enabled case):

1. After the `$mockUserApiListener` mock builder block, add:

```php
        $mockItemSetSitesHydrationListener = $this->getMockBuilder(ItemSetSitesHydrationListener::class)
            ->disableOriginalConstructor()
            ->getMock();
```

2. Change `$this->serviceLocator->expects($this->exactly(12))` to `$this->exactly(13)`.
3. Add to the `willReturnMap` array:

```php
                [ItemSetSitesHydrationListener::class, $mockItemSetSitesHydrationListener],
```

4. Change `$this->sharedEventManager->expects($this->exactly(11))` to `$this->exactly(12)`.
5. In `withConsecutive`, insert this entry **between** the `MediaAdapter`/`api.search.query` entry and the `Omeka\Api\Adapter\UserAdapter`/`api.hydrate.post` entry — order must match `attachListeners`:

```php
                [
                    $this->equalTo('Omeka\Api\Adapter\ItemSetAdapter'),
                    $this->equalTo('api.hydrate.post'),
                    $this->identicalTo([$mockItemSetSitesHydrationListener, '__invoke'])
                ],
```

In **`testAttachListenersSkipsQueryListenersWhenDisabled`**:

1. Add the same mock builder block after `$mockUserApiListener`.
2. Update the comment above `$settings` to read:

```php
        // Isolation disabled: the five api.search.query listeners must NOT be
        // attached, but the form, CAS, item-set site assignment and user-API
        // listeners still are — the kill switch governs read-side filtering only.
```

3. Change `$this->serviceLocator->expects($this->exactly(7))` to `$this->exactly(8)`.
4. Add to the `willReturnMap` array:

```php
                [ItemSetSitesHydrationListener::class, $mockItemSetSitesHydrationListener],
```

5. Change `$this->sharedEventManager->expects($this->exactly(6))` to `$this->exactly(7)`.
6. In `withConsecutive`, insert the same `ItemSetAdapter` entry **between** the `CAS\Controller\LoginController` entry and the `Omeka\Api\Adapter\UserAdapter`/`api.hydrate.post` entry.

- [ ] **Step 8: Run the full suite**

Run: `make test`
Expected: PASS — including both `ModuleTest` attachment tests.

- [ ] **Step 9: Commit**

```bash
git add src/Listener/ItemSetSitesHydrationListener.php test/IsolatedSitesTest/Listener/ItemSetSitesHydrationListenerTest.php test/IsolatedSitesTest/Module/ModuleTest.php config/module.config.php Module.php
git commit -m "feat(item-sets): reconcile site assignments on save

Consumes o:site[] on ItemSetAdapter api.hydrate.post for site_editor and
site_manager, creating and removing SiteItemSet rows via the entity
manager. ItemSet::\$siteItemSets has no cascade, so collection add/remove
would silently no-op.

Rows for sites the user has no permission on are never touched, so a Site
Editor cannot detach an item set from a site they cannot see.

Refs #32"
```

---

### Task 3: `ItemSetSitesFormListener` and fieldset

**Files:**
- Create: `src/Listener/ItemSetSitesFormListener.php`
- Create: `view/isolated-sites/item-set-sites-fieldset.phtml`
- Create: `test/IsolatedSitesTest/Listener/ItemSetSitesFormListenerTest.php`
- Modify: `config/module.config.php`
- Modify: `Module.php`

**Interfaces:**
- Consumes: `GrantedSites::forUser(int): array` from Task 1. Emits the `o:site[]` field that Task 2's listener reads.
- Produces: `ItemSetSitesFormListener::addSectionNav(EventInterface): void`, `::renderFieldset(EventInterface): void`, and `::buildSiteOptions($itemSet = null): array` returning `[['id' => int, 'title' => string, 'selected' => bool], ...]`. Constructor: `new ItemSetSitesFormListener(AuthenticationService $auth, GrantedSites $grantedSites, EntityManager $entityManager, UserSettings $userSettings)`.

`buildSiteOptions` is public so it can be tested without a real `PhpRenderer` (`partial()` is `__call` magic and cannot be mocked). `renderFieldset` stays a thin wrapper around it.

- [ ] **Step 1: Write the failing test**

Create `test/IsolatedSitesTest/Listener/ItemSetSitesFormListenerTest.php`:

```php
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

    private function makeSite(int $id, string $title): Site
    {
        $site = new Site();
        $reflection = new \ReflectionProperty(Site::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($site, $id);
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter ItemSetSitesFormListenerTest`
Expected: FAIL — `Class "IsolatedSites\Listener\ItemSetSitesFormListener" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `src/Listener/ItemSetSitesFormListener.php`:

```php
<?php
declare(strict_types=1);

namespace IsolatedSites\Listener;

use Doctrine\ORM\EntityManager;
use IsolatedSites\Module;
use IsolatedSites\Service\GrantedSites;
use Laminas\Authentication\AuthenticationService;
use Laminas\EventManager\EventInterface;
use Omeka\Entity\Site;
use Omeka\Settings\UserSettings;

/**
 * Adds a Sites section to the item-set add/edit form for site-scoped roles.
 *
 * Core's item-set form has no site selector — assignment lives on Site >
 * Resources, which site_editor may not reach — so without this a Site Editor
 * cannot place an item set in their own site.
 */
class ItemSetSitesFormListener
{
    /** Must match the fieldset id in the partial for the tab to activate. */
    public const SECTION_ID = 'isolatedsites-item-set-sites';

    private const MANAGED_ROLES = [
        Module::ROLE_SITE_EDITOR,
        Module::ROLE_SITE_MANAGER,
    ];

    private $auth;
    private $grantedSites;
    private $entityManager;
    private $userSettings;

    public function __construct(
        AuthenticationService $auth,
        GrantedSites $grantedSites,
        EntityManager $entityManager,
        UserSettings $userSettings
    ) {
        $this->auth = $auth;
        $this->grantedSites = $grantedSites;
        $this->entityManager = $entityManager;
        $this->userSettings = $userSettings;
    }

    /**
     * Triggered with $filter = true, so params must be written back.
     */
    public function addSectionNav(EventInterface $event): void
    {
        if (!$this->isManagedUser()) {
            return;
        }

        $sectionNav = $event->getParam('section_nav');
        $sectionNav[self::SECTION_ID] = 'Sites'; // @translate
        $event->setParam('section_nav', $sectionNav);
    }

    /**
     * Triggered with $filter = false, so the markup must be echoed.
     */
    public function renderFieldset(EventInterface $event): void
    {
        if (!$this->isManagedUser()) {
            return;
        }

        $view = $event->getTarget();
        $itemSet = isset($view->itemSet) ? $view->itemSet : null;

        echo $view->partial('isolated-sites/item-set-sites-fieldset', [
            'sectionId' => self::SECTION_ID,
            'sites' => $this->buildSiteOptions($itemSet),
        ]);
    }

    /**
     * @param \Omeka\Api\Representation\ItemSetRepresentation|null $itemSet
     * @return array<int, array{id: int, title: string, selected: bool}>
     */
    public function buildSiteOptions($itemSet = null): array
    {
        $user = $this->auth->getIdentity();
        if (!$user) {
            return [];
        }

        $granted = $this->grantedSites->forUser((int) $user->getId());
        if (!$granted) {
            return [];
        }

        $selected = array_intersect(
            $itemSet
                ? array_map('intval', array_keys($itemSet->sites()))
                : $this->defaultSiteIds((int) $user->getId()),
            $granted
        );

        $sites = $this->entityManager
            ->getRepository(Site::class)
            ->findBy(['id' => $granted], ['title' => 'ASC']);

        $options = [];
        foreach ($sites as $site) {
            $id = (int) $site->getId();
            $options[] = [
                'id' => $id,
                'title' => (string) $site->getTitle(),
                'selected' => in_array($id, $selected, true),
            ];
        }

        return $options;
    }

    /**
     * Reuses the core setting that already drives site assignment for new items.
     *
     * @return int[]
     */
    private function defaultSiteIds(int $userId): array
    {
        $this->userSettings->setTargetId($userId);
        $defaults = $this->userSettings->get('default_item_sites', []);

        return is_array($defaults) ? array_map('intval', $defaults) : [];
    }

    private function isManagedUser(): bool
    {
        $user = $this->auth->getIdentity();

        return $user && in_array($user->getRole(), self::MANAGED_ROLES, true);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter ItemSetSitesFormListenerTest`
Expected: PASS — 10 tests.

- [ ] **Step 5: Create the fieldset partial**

Create `view/isolated-sites/item-set-sites-fieldset.phtml`. The hidden `o:site[]` input is **required**: with no boxes checked a browser omits the key entirely, and the hydration listener reads an absent key as "leave alone". The blank value guarantees the key is always present on a form POST, so unchecking everything means "unassign". `normalizeIds` discards the blank.

```php
<?php
$translate = $this->plugin('translate');
$escape = $this->plugin('escapeHtml');
?>
<fieldset id="<?php echo $escape($sectionId); ?>" class="section">
    <?php if (!$sites): ?>
    <p>
        <?php echo $translate('You have no sites assigned. Ask an administrator to grant you permissions on a site before assigning this item set.'); ?>
    </p>
    <?php else: ?>
    <div class="field">
        <div class="field-meta">
            <span class="label"><?php echo $translate('Sites'); ?></span>
            <div class="field-description">
                <?php echo $translate('Select the sites this item set belongs to. Only sites you have permissions on are listed.'); ?>
            </div>
        </div>
        <div class="inputs">
            <input type="hidden" name="o:site[]" value="">
            <?php foreach ($sites as $site): ?>
            <label>
                <input type="checkbox" name="o:site[]" value="<?php echo (int) $site['id']; ?>"<?php echo $site['selected'] ? ' checked' : ''; ?>>
                <?php echo $escape($site['title']); ?>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</fieldset>
```

- [ ] **Step 6: Register the service**

In `config/module.config.php`, add to the `use` statements:

```php
use IsolatedSites\Listener\ItemSetSitesFormListener;
```

Add this factory inside `'service_manager' => ['factories' => [ ... ]]`, after the `ItemSetSitesHydrationListener::class` factory:

```php
            ItemSetSitesFormListener::class => function ($services) {
                return new ItemSetSitesFormListener(
                    $services->get('Omeka\AuthenticationService'),
                    $services->get(GrantedSites::class),
                    $services->get('Omeka\EntityManager'),
                    $services->get('Omeka\Settings\User')
                );
            },
```

- [ ] **Step 7: Attach the listeners**

In `Module.php`, add to the `use` statements:

```php
use IsolatedSites\Listener\ItemSetSitesFormListener;
```

In `attachListeners`, directly below the `api.hydrate.post` attachment added in Task 2:

```php
        $formListener = $this->serviceLocator->get(ItemSetSitesFormListener::class);
        foreach (['add', 'edit'] as $action) {
            $sharedEventManager->attach(
                'Omeka\Controller\Admin\ItemSet',
                "view.$action.section_nav",
                [$formListener, 'addSectionNav']
            );
            $sharedEventManager->attach(
                'Omeka\Controller\Admin\ItemSet',
                "view.$action.form.after",
                [$formListener, 'renderFieldset']
            );
        }
```

- [ ] **Step 8: Update `ModuleTest` for the four new attachments**

Building on the counts from Task 2 Step 7. The four attachments are emitted in this order by the `foreach (['add', 'edit'] as $action)` loop: `view.add.section_nav`, `view.add.form.after`, `view.edit.section_nav`, `view.edit.form.after`. The listener is fetched once into `$formListener`, so this adds exactly **one** `get` call.

Add the import:

```php
use IsolatedSites\Listener\ItemSetSitesFormListener;
```

In **both** `testAttachListeners` and `testAttachListenersSkipsQueryListenersWhenDisabled`:

1. Add the mock builder block after `$mockItemSetSitesHydrationListener`:

```php
        $mockItemSetSitesFormListener = $this->getMockBuilder(ItemSetSitesFormListener::class)
            ->disableOriginalConstructor()
            ->getMock();
```

2. Add to the `willReturnMap` array:

```php
                [ItemSetSitesFormListener::class, $mockItemSetSitesFormListener],
```

3. Insert these four entries into `withConsecutive`, immediately **after** the `Omeka\Api\Adapter\ItemSetAdapter`/`api.hydrate.post` entry added in Task 2 and **before** the `Omeka\Api\Adapter\UserAdapter`/`api.hydrate.post` entry:

```php
                [
                    $this->equalTo('Omeka\Controller\Admin\ItemSet'),
                    $this->equalTo('view.add.section_nav'),
                    $this->identicalTo([$mockItemSetSitesFormListener, 'addSectionNav'])
                ],
                [
                    $this->equalTo('Omeka\Controller\Admin\ItemSet'),
                    $this->equalTo('view.add.form.after'),
                    $this->identicalTo([$mockItemSetSitesFormListener, 'renderFieldset'])
                ],
                [
                    $this->equalTo('Omeka\Controller\Admin\ItemSet'),
                    $this->equalTo('view.edit.section_nav'),
                    $this->identicalTo([$mockItemSetSitesFormListener, 'addSectionNav'])
                ],
                [
                    $this->equalTo('Omeka\Controller\Admin\ItemSet'),
                    $this->equalTo('view.edit.form.after'),
                    $this->identicalTo([$mockItemSetSitesFormListener, 'renderFieldset'])
                ],
```

4. Update the counts:

| Test | `serviceLocator` `get` | `sharedEventManager` `attach` |
|---|---|---|
| `testAttachListeners` | `exactly(13)` → `exactly(14)` | `exactly(12)` → `exactly(16)` |
| `testAttachListenersSkipsQueryListenersWhenDisabled` | `exactly(8)` → `exactly(9)` | `exactly(7)` → `exactly(11)` |

- [ ] **Step 9: Run the full suite**

Run: `make test`
Expected: PASS — including both `ModuleTest` attachment tests.

- [ ] **Step 10: Commit**

```bash
git add src/Listener/ItemSetSitesFormListener.php view/isolated-sites/item-set-sites-fieldset.phtml test/IsolatedSitesTest/Listener/ItemSetSitesFormListenerTest.php test/IsolatedSitesTest/Module/ModuleTest.php config/module.config.php Module.php
git commit -m "feat(item-sets): add Sites section to the item-set form

Injects a Sites tab and fieldset into core's item-set add/edit form for
site_editor and site_manager, listing only the user's granted sites. New
item sets prefill from the core default_item_sites user setting.

Refs #32"
```

---

### Task 4: Update the README role matrix

The capability table now misdescribes what `site_editor` can do.

**Files:**
- Modify: `README.md:228-250`

**Interfaces:**
- Consumes: nothing. Produces: nothing.

- [ ] **Step 1: Read the current role documentation**

Run: `sed -n '220,260p' README.md`
Expected: the role description table (line ~228) and the capability matrix (line ~237), including the note at ~250 about `site_manager` being gated by core's per-site permission.

- [ ] **Step 2: Update the capability matrix**

Add a row to the capability matrix (the table starting at line ~237, with columns `Capability | editor (core) | site_researcher | site_editor | site_manager`), keeping the existing column formatting and tick/cross convention already used in that table:

```markdown
| Assign an item set to a site | Via **Sites > Resources** | ✗ | Via the item set's **Sites** tab, granted sites only | Via the item set's **Sites** tab or **Sites > Resources**, granted sites only |
```

- [ ] **Step 3: Document the behaviour**

Immediately below the matrix's existing note about `site_manager` (line ~250), add:

```markdown
> Item sets are assigned to sites from the **Sites** tab of the item-set form.
> Only the sites you have permissions on are listed, and assignments to other
> sites are preserved untouched when you save. New item sets are pre-selected
> from your **Default sites for new items** user setting.
```

- [ ] **Step 4: Verify the tables still render**

Run: `sed -n '220,262p' README.md`
Expected: both tables have consistent column counts; the new row has 5 cells matching the 5-column header.

- [ ] **Step 5: Commit**

```bash
git add README.md
git commit -m "docs(readme): document item-set site assignment

Refs #32"
```

---

## Manual Verification

Automated tests are unit-level against stubs, so exercise the real flow once before opening the PR. `docker-compose.yml` is at the repo root; the README documents seeded users including a Site Editor for **site-a**.

- [ ] Log in as the Site Editor and go to **Item sets → Add new item set**. Confirm a **Sites** tab appears listing only their sites.
- [ ] Save with a site checked. Confirm the item set now appears under that site and is visible to another user of the same site.
- [ ] Edit it, uncheck the site, save. Confirm the assignment is gone.
- [ ] As a global admin, assign the item set to a second site the editor has no permission on. Then, as the editor, save the item-set form again. **Confirm the second site's assignment survives.** This is the isolation guarantee.
- [ ] Confirm a global admin sees no Sites tab on the item-set form and that Site → Resources still behaves exactly as before.

## Execution Order

Task 1 → Task 2 → Task 3 → Task 4. Task 2 and Task 3 both depend on Task 1's service. Task 3's form emits the field Task 2's listener consumes, but each is independently testable.

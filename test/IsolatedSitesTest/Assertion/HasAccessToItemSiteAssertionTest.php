<?php
declare(strict_types=1);

namespace IsolatedSitesTest\Assertion;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result as DbalResult;
use IsolatedSites\Assertion\HasAccessToItemSiteAssertion;
use Laminas\Permissions\Acl\Acl;
use Laminas\Permissions\Acl\Role\GenericRole;
use Omeka\Entity\Item;
use Omeka\Entity\Media;
use Omeka\Settings\UserSettings;
use PHPUnit\Framework\TestCase;

class HasAccessToItemSiteAssertionTest extends TestCase
{
    private $userSettings;
    private $connection;
    private $assertion;
    private $acl;

    protected function setUp(): void
    {
        $this->userSettings = $this->createMock(UserSettings::class);
        $this->connection = $this->createMock(Connection::class);
        $this->assertion = new HasAccessToItemSiteAssertion(
            $this->userSettings,
            $this->connection
        );
        $this->acl = new Acl();
    }

    public function testReturnsFalseWhenNoResource(): void
    {
        $role = $this->createMockRole(1);

        $result = $this->assertion->assert($this->acl, $role, null, 'read');

        $this->assertFalse($result);
    }

    public function testReturnsFalseWhenNoRole(): void
    {
        $item = $this->createMockItem(1);

        $result = $this->assertion->assert($this->acl, null, $item, 'read');

        $this->assertFalse($result);
    }

    public function testAllowsAccessWhenLimitToGrantedSitesIsDisabled(): void
    {
        $userId = 1;
        $role = $this->createMockRole($userId);
        $item = $this->createMockItem(1);

        $this->userSettings->expects($this->once())
            ->method('setTargetId')
            ->with($userId);

        $this->userSettings->expects($this->once())
            ->method('get')
            ->with('limit_to_granted_sites', true)
            ->willReturn(false);

        $result = $this->assertion->assert($this->acl, $role, $item, 'read');

        $this->assertTrue($result);
    }

    public function testDeniesAccessWhenUserHasNoGrantedSites(): void
    {
        $userId = 1;
        $role = $this->createMockRole($userId);
        $item = $this->createMockItem(1);

        $this->userSettings->expects($this->once())
            ->method('setTargetId')
            ->with($userId);

        $this->userSettings->expects($this->once())
            ->method('get')
            ->with('limit_to_granted_sites', true)
            ->willReturn(true);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                'SELECT site_id FROM site_permission WHERE user_id = :user_id',
                ['user_id' => $userId]
            )
            ->willReturn($this->createDbalResult([]));

        $result = $this->assertion->assert($this->acl, $role, $item, 'read');

        $this->assertFalse($result);
    }

    public function testAllowsAccessWhenItemIsInGrantedSite(): void
    {
        $userId = 1;
        $itemId = 10;
        $grantedSiteIds = [1, 2, 3];
        $itemSiteIds = [2];

        $role = $this->createMockRole($userId);
        $item = $this->createMockItem($itemId);

        $this->userSettings->expects($this->once())
            ->method('setTargetId')
            ->with($userId);

        $this->userSettings->expects($this->once())
            ->method('get')
            ->with('limit_to_granted_sites', true)
            ->willReturn(true);

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(function ($sql) use ($grantedSiteIds, $itemSiteIds) {
                if (strpos($sql, 'site_permission') !== false) {
                    return $this->createDbalResult($grantedSiteIds);
                }
                if (strpos($sql, 'item_site') !== false) {
                    return $this->createDbalResult($itemSiteIds);
                }

                $this->fail('Unexpected SQL: ' . $sql);
            });

        $result = $this->assertion->assert($this->acl, $role, $item, 'read');

        $this->assertTrue($result);
    }

    public function testDeniesAccessWhenItemIsNotInGrantedSite(): void
    {
        $userId = 1;
        $itemId = 10;
        $grantedSiteIds = [1, 2, 3];
        $itemSiteIds = [4];

        $role = $this->createMockRole($userId);
        $item = $this->createMockItem($itemId);

        $this->userSettings->expects($this->once())
            ->method('setTargetId')
            ->with($userId);

        $this->userSettings->expects($this->once())
            ->method('get')
            ->with('limit_to_granted_sites', true)
            ->willReturn(true);

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(function ($sql) use ($grantedSiteIds, $itemSiteIds) {
                if (strpos($sql, 'site_permission') !== false) {
                    return $this->createDbalResult($grantedSiteIds);
                }
                if (strpos($sql, 'item_site') !== false) {
                    return $this->createDbalResult($itemSiteIds);
                }

                $this->fail('Unexpected SQL: ' . $sql);
            });

        $result = $this->assertion->assert($this->acl, $role, $item, 'read');

        $this->assertFalse($result);
    }

    public function testAllowsOwnerOfItemThatHasNoSites(): void
    {
        $userId = 5;
        $role = $this->createMockRole($userId);
        $item = $this->createMockItemWithOwner(10, $userId);

        $this->userSettings->expects($this->once())
            ->method('setTargetId')
            ->with($userId);

        $this->userSettings->expects($this->once())
            ->method('get')
            ->with('limit_to_granted_sites', true)
            ->willReturn(true);

        // The owner short-circuit must grant access before any site query runs,
        // so a user can edit their own not-yet-sited content.
        $this->connection->expects($this->never())
            ->method('executeQuery');

        $result = $this->assertion->assert($this->acl, $role, $item, 'update');

        $this->assertTrue($result);
    }

    public function testAllowsAccessWhenMediaItemAndSitesOverlap(): void
    {
        $userId = 1;
        $itemId = 10;
        $mediaId = 99;
        $grantedSiteIds = [1, 2];
        $itemSiteIds = [2];
        $mediaSiteIds = [2, 5];

        $role = $this->createMockRole($userId);
        $item = $this->createMockItem($itemId);
        $media = $this->createMockMedia($mediaId, $item);

        $this->userSettings->expects($this->once())
            ->method('setTargetId')
            ->with($userId);

        $this->userSettings->expects($this->once())
            ->method('get')
            ->with('limit_to_granted_sites', true)
            ->willReturn(true);

        $this->connection->expects($this->exactly(3))
            ->method('executeQuery')
            ->willReturnCallback(function ($sql) use ($grantedSiteIds, $itemSiteIds, $mediaSiteIds) {
                if (strpos($sql, 'site_permission') !== false) {
                    return $this->createDbalResult($grantedSiteIds);
                }
                if (strpos($sql, 'item_site') !== false) {
                    return $this->createDbalResult($itemSiteIds);
                }
                if (strpos($sql, 'media_site') !== false) {
                    return $this->createDbalResult($mediaSiteIds);
                }

                $this->fail('Unexpected SQL: ' . $sql);
            });

        $result = $this->assertion->assert($this->acl, $role, $media, 'update');

        $this->assertTrue($result);
    }

    public function testAllowsAccessWhenOnlyMediaSitesMatchGrantedAccess(): void
    {
        $userId = 1;
        $itemId = 10;
        $mediaId = 200;
        $grantedSiteIds = [7];
        $itemSiteIds = [];
        $mediaSiteIds = [7];

        $role = $this->createMockRole($userId);
        $item = $this->createMockItem($itemId);
        $media = $this->createMockMedia($mediaId, $item);

        $this->userSettings->expects($this->once())
            ->method('setTargetId')
            ->with($userId);

        $this->userSettings->expects($this->once())
            ->method('get')
            ->with('limit_to_granted_sites', true)
            ->willReturn(true);

        $this->connection->expects($this->exactly(3))
            ->method('executeQuery')
            ->willReturnCallback(function ($sql) use ($grantedSiteIds, $itemSiteIds, $mediaSiteIds) {
                if (strpos($sql, 'site_permission') !== false) {
                    return $this->createDbalResult($grantedSiteIds);
                }
                if (strpos($sql, 'item_site') !== false) {
                    return $this->createDbalResult($itemSiteIds);
                }
                if (strpos($sql, 'media_site') !== false) {
                    return $this->createDbalResult($mediaSiteIds);
                }

                $this->fail('Unexpected SQL: ' . $sql);
            });

        $result = $this->assertion->assert($this->acl, $role, $media, 'update');

        $this->assertTrue($result);
    }

    public function testDeniesAccessWhenMediaHasNoGrantedSite(): void
    {
        $userId = 1;
        $itemId = 10;
        $mediaId = 300;
        $grantedSiteIds = [1];
        $itemSiteIds = [4];
        $mediaSiteIds = [5];

        $role = $this->createMockRole($userId);
        $item = $this->createMockItem($itemId);
        $media = $this->createMockMedia($mediaId, $item);

        $this->userSettings->expects($this->once())
            ->method('setTargetId')
            ->with($userId);

        $this->userSettings->expects($this->once())
            ->method('get')
            ->with('limit_to_granted_sites', true)
            ->willReturn(true);

        $this->connection->expects($this->exactly(3))
            ->method('executeQuery')
            ->willReturnCallback(function ($sql) use ($grantedSiteIds, $itemSiteIds, $mediaSiteIds) {
                if (strpos($sql, 'site_permission') !== false) {
                    return $this->createDbalResult($grantedSiteIds);
                }
                if (strpos($sql, 'item_site') !== false) {
                    return $this->createDbalResult($itemSiteIds);
                }
                if (strpos($sql, 'media_site') !== false) {
                    return $this->createDbalResult($mediaSiteIds);
                }

                $this->fail('Unexpected SQL: ' . $sql);
            });

        $result = $this->assertion->assert($this->acl, $role, $media, 'update');

        $this->assertFalse($result);
    }

    public function resourceRequests(): array
    {
        return [
            ['item', 'post', 'id'], ['item', 'query', 'id'], ['item', 'route', 'item-id'],
            ['item', 'route', 'item_id'], ['item', 'route', 'id'],
            ['media', 'post', 'media_id'], ['media', 'post', 'id'],
            ['media', 'query', 'media_id'], ['media', 'query', 'id'],
            ['media', 'route', 'media-id'], ['media', 'route', 'media_id'], ['media', 'route', 'id'],
        ];
    }

    /** @dataProvider resourceRequests */
    public function testResolvesRequestAndRouteResources(string $kind, string $source, string $key): void
    {
        $isMedia = strpos($kind, 'media') === 0;
        $item = $this->createMockItem(10);
        $media = $this->createMockMedia(20, $item);
        $id = $isMedia ? 20 : 10;
        $request = new \Laminas\Http\PhpEnvironment\Request();
        if ($source === 'post') {
            $request->getPost()->set($key, (string) $id);
        } elseif ($source === 'query') {
            $request->getQuery()->set($key, (string) $id);
        }
        $route = new \Laminas\Router\RouteMatch($source === 'route' ? [$key => $id] : []);
        $event = new \Laminas\Mvc\MvcEvent();
        $event->setRouteMatch($route);
        $application = new class ($event) {
            private $event;
            public function __construct($event) { $this->event = $event; }
            public function getMvcEvent() { return $this->event; }
        };
        $api = $this->createMock(\Omeka\Api\Manager::class);
        $response = $this->createMock(\Omeka\Api\Response::class);
        $response->method('getContent')->willReturn($isMedia ? $media : $item);
        $api->expects($this->once())->method('read')->with($isMedia ? 'media' : 'items', $id)->willReturn($response);
        $services = new \Laminas\ServiceManager\ServiceManager(['services' => [
            'Request' => $request, 'Application' => $application, 'Omeka\ApiManager' => $api,
        ]]);
        $this->assertion->setServiceLocator($services);
        $this->userSettings->method('get')->willReturn(true);
        $this->connection->method('executeQuery')->willReturnCallback(function () {
            return $this->createDbalResult([7]);
        });
        $classes = ['item' => \Omeka\Api\Adapter\ItemAdapter::class,
            'media' => \Omeka\Api\Adapter\MediaAdapter::class,
            'item-controller' => \Omeka\Controller\Admin\Item::class,
            'media-controller' => \Omeka\Controller\Admin\Media::class];
        $resource = $this->createMock($classes[$kind]);
        $this->assertTrue($this->assertion->assert($this->acl, $this->createMockRole(1), $resource, 'update'));
    }

    public function testMissingApiAndInvalidRequestIdsFailClosed(): void
    {
        $this->userSettings->method('get')->willReturn(true);
        $role = $this->createMockRole(1);
        $adapter = $this->createMock(\Omeka\Api\Adapter\ItemAdapter::class);
        $this->assertFalse($this->assertion->assert($this->acl, $role, $adapter, 'update'));
        foreach ([null, '', [], new \stdClass(), 0, -1, 10] as $id) {
            $request = new \Laminas\Http\PhpEnvironment\Request();
            $request->getPost()->set('id', $id);
            $services = new \Laminas\ServiceManager\ServiceManager(['services' => ['Request' => $request]]);
            $this->assertion->setServiceLocator($services);
            $this->assertFalse($this->assertion->assert($this->acl, $role, $adapter, 'update'));
        }
    }

    public function testApiFailureDoesNotGrantAccess(): void
    {
        $this->userSettings->method('get')->willReturn(true);
        $request = new \Laminas\Http\PhpEnvironment\Request();
        $request->getPost()->set('id', 10);
        foreach ([new \Omeka\Api\Exception\NotFoundException(), new \RuntimeException()] as $error) {
            foreach ([\Omeka\Api\Adapter\ItemAdapter::class, \Omeka\Api\Adapter\MediaAdapter::class] as $class) {
                $api = $this->createMock(\Omeka\Api\Manager::class);
                $api->method('read')->willThrowException($error);
                $services = new \Laminas\ServiceManager\ServiceManager(['services' => [
                    'Request' => $request, 'Omeka\ApiManager' => $api,
                ]]);
                $this->assertion->setServiceLocator($services);
                $this->assertFalse($this->assertion->assert($this->acl, $this->createMockRole(1), $this->createMock($class)));
            }
        }
    }

    public function testRepresentationsAndEntityWrappersUseItemOwnership(): void
    {
        $this->userSettings->method('get')->willReturn(true);
        $this->connection->expects($this->never())->method('executeQuery');
        $owner = $this->createMock(\Omeka\Api\Representation\UserRepresentation::class);
        $owner->method('id')->willReturn(1);
        $item = $this->createMock(\Omeka\Api\Representation\ItemRepresentation::class);
        $item->method('id')->willReturn(10);
        $item->method('owner')->willReturn($owner);
        $media = $this->createMock(\Omeka\Api\Representation\MediaRepresentation::class);
        $media->method('item')->willReturn($item);
        $entity = $this->createMockItemWithOwner(10, 1);
        $mediaEntity = $this->createMockMedia(20, $entity);
        foreach ([$item, $media, $entity, $mediaEntity] as $resource) {
            if ($resource instanceof \Laminas\Permissions\Acl\Resource\ResourceInterface) {
                $this->assertTrue($this->assertion->assert($this->acl, $this->createMockRole(1), $resource));
            }
            foreach (['getEntity', 'resource'] as $method) {
                $wrapper = $this->getMockBuilder(\Laminas\Permissions\Acl\Resource\GenericResource::class)
                    ->setConstructorArgs(['wrapped'])->addMethods([$method])->getMock();
                $wrapper->method($method)->willReturn($resource);
                // getEntity accepts entities, while resource also accepts representations.
                $expected = $method === 'resource' || $resource instanceof Item || $resource instanceof Media;
                $this->assertSame($expected, $this->assertion->assert($this->acl, $this->createMockRole(1), $wrapper));
            }
        }
    }

    private function createMockRole(int $userId)
    {
        return new class($userId) extends GenericRole {
            private $userId;

            public function __construct(int $userId)
            {
                parent::__construct('role');
                $this->userId = $userId;
            }

            public function getId(): int
            {
                return $this->userId;
            }
        };
    }

    private function createMockItem(int $itemId)
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->getMock();

        $item->method('getId')->willReturn($itemId);

        return $item;
    }

    private function createMockItemWithOwner(int $itemId, int $ownerId)
    {
        $owner = $this->getMockBuilder(\Omeka\Entity\User::class)
            ->disableOriginalConstructor()
            ->getMock();
        $owner->method('getId')->willReturn($ownerId);

        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->getMock();
        $item->method('getId')->willReturn($itemId);
        $item->method('getOwner')->willReturn($owner);

        return $item;
    }

    private function createMockMedia(int $mediaId, Item $item)
    {
        $media = $this->getMockBuilder(Media::class)
            ->disableOriginalConstructor()
            ->getMock();

        $media->method('getId')->willReturn($mediaId);
        $media->method('getItem')->willReturn($item);

        return $media;
    }

    private function createDbalResult(array $firstColumn): DbalResult
    {
        $driverResult = new class($firstColumn) implements \Doctrine\DBAL\Driver\Result {
            private $firstColumn;
            private $position = 0;

            public function __construct(array $firstColumn)
            {
                $this->firstColumn = $firstColumn;
            }

            public function fetchNumeric()
            {
                return false;
            }

            public function fetchAssociative()
            {
                return false;
            }

            public function fetchOne()
            {
                return $this->position < count($this->firstColumn)
                    ? $this->firstColumn[$this->position++]
                    : false;
            }

            public function fetchAllNumeric(): array
            {
                return [];
            }

            public function fetchAllAssociative(): array
            {
                return [];
            }

            public function fetchFirstColumn(): array
            {
                return $this->firstColumn;
            }

            public function rowCount(): int
            {
                return count($this->firstColumn);
            }

            public function columnCount(): int
            {
                return empty($this->firstColumn) ? 0 : 1;
            }

            public function free(): void
            {
            }
        };

        return new DbalResult($driverResult, $this->connection);
    }
}

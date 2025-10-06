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
            ->with('limit_to_granted_sites', false)
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
            ->with('limit_to_granted_sites', false)
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
            ->with('limit_to_granted_sites', false)
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
            ->with('limit_to_granted_sites', false)
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
            ->with('limit_to_granted_sites', false)
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
            ->with('limit_to_granted_sites', false)
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
            ->with('limit_to_granted_sites', false)
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

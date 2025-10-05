<?php
declare(strict_types=1);

namespace IsolatedSitesTest\Assertion;

use IsolatedSites\Assertion\HasAccessToItemSiteAssertion;
use Laminas\Permissions\Acl\Acl;
use Laminas\Permissions\Acl\Role\GenericRole;
use Omeka\Entity\Item;
use Omeka\Settings\UserSettings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Result;
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

    public function testReturnsTrueWhenNoResource()
    {
        $role = $this->createMockRole(1);
        
        $result = $this->assertion->assert($this->acl, $role, null, 'read');
        
        $this->assertTrue($result); // Should apply deny rule (deny access)
    }

    public function testReturnsTrueWhenNoRole()
    {
        $item = $this->createMockItem(1);
        
        $result = $this->assertion->assert($this->acl, null, $item, 'read');
        
        $this->assertTrue($result); // Should apply deny rule (deny access)
    }

    public function testAllowsAccessWhenLimitToGrantedSitesIsDisabled()
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
        
        $this->assertFalse($result); // Should NOT apply deny rule (allow access)
    }

    public function testDeniesAccessWhenUserHasNoGrantedSites()
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

        // Mock empty granted sites
        $stmt = $this->createMock(Result::class);
        $stmt->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn([]);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                'SELECT site_id FROM site_permission WHERE user_id = :user_id',
                ['user_id' => $userId]
            )
            ->willReturn($stmt);

        $result = $this->assertion->assert($this->acl, $role, $item, 'read');
        
        $this->assertTrue($result); // Should apply deny rule (deny access)
    }

    public function testAllowsAccessWhenItemIsInGrantedSite()
    {
        $userId = 1;
        $itemId = 10;
        $grantedSiteIds = [1, 2, 3];
        
        $role = $this->createMockRole($userId);
        $item = $this->createMockItem($itemId);

        $this->userSettings->expects($this->once())
            ->method('setTargetId')
            ->with($userId);

        $this->userSettings->expects($this->once())
            ->method('get')
            ->with('limit_to_granted_sites', false)
            ->willReturn(true);

        // Mock granted sites query
        $stmt1 = $this->createMock(Result::class);
        $stmt1->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn($grantedSiteIds);

        // Mock item site check query
        $stmt2 = $this->createMock(Result::class);
        $stmt2->expects($this->once())
            ->method('fetchOne')
            ->willReturn(1); // Item found in granted site

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(function($sql, $params) use ($stmt1, $stmt2) {
                if (strpos($sql, 'site_permission') !== false) {
                    return $stmt1;
                } else {
                    return $stmt2;
                }
            });

        $result = $this->assertion->assert($this->acl, $role, $item, 'read');
        
        $this->assertFalse($result); // Should NOT apply deny rule (allow access)
    }

    public function testDeniesAccessWhenItemIsNotInGrantedSite()
    {
        $userId = 1;
        $itemId = 10;
        $grantedSiteIds = [1, 2, 3];
        
        $role = $this->createMockRole($userId);
        $item = $this->createMockItem($itemId);

        $this->userSettings->expects($this->once())
            ->method('setTargetId')
            ->with($userId);

        $this->userSettings->expects($this->once())
            ->method('get')
            ->with('limit_to_granted_sites', false)
            ->willReturn(true);

        // Mock granted sites query
        $stmt1 = $this->createMock(Result::class);
        $stmt1->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn($grantedSiteIds);

        // Mock item site check query
        $stmt2 = $this->createMock(Result::class);
        $stmt2->expects($this->once())
            ->method('fetchOne')
            ->willReturn(0); // Item not found in granted site

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(function($sql, $params) use ($stmt1, $stmt2) {
                if (strpos($sql, 'site_permission') !== false) {
                    return $stmt1;
                } else {
                    return $stmt2;
                }
            });

        $result = $this->assertion->assert($this->acl, $role, $item, 'read');
        
        $this->assertTrue($result); // Should apply deny rule (deny access)
    }

    private function createMockRole(int $userId)
    {
        $role = $this->getMockBuilder(GenericRole::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $role->method('getId')->willReturn($userId);
        
        return $role;
    }

    private function createMockItem(int $itemId)
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $item->method('getId')->willReturn($itemId);
        
        return $item;
    }
}

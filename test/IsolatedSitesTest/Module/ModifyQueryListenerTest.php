<?php

namespace IsolatedSitesTest\Listener;

use IsolatedSites\Listener\ModifyQueryListener;
use IsolatedSites\Listener\ModifyItemSetQueryListener;
use Laminas\EventManager\EventInterface;
use Laminas\Authentication\AuthenticationService;
use Omeka\Settings\UserSettings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Omeka\Entity\User;

class ModifyQueryListenerTest extends TestCase
{
    private $authService;
    private $userSettings;
    private $connection;
    private $event;
    private $queryBuilder;
    private $user;

    protected function setUp(): void
    {
        // Mock dependencies
        $this->authService = $this->createMock(AuthenticationService::class);
        $this->userSettings = $this->createMock(UserSettings::class);
        $this->connection = $this->createMock(Connection::class);
        $this->event = $this->createMock(EventInterface::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->user = $this->createMock(User::class);
    }

    public function testGlobalAdminBypassesFilter()
    {
        // Setup
        $this->user->method('getRole')->willReturn('global_admin');
        $this->authService->method('getIdentity')->willReturn($this->user);

        $listener = new ModifyQueryListener(
            $this->authService,
            $this->userSettings,
            $this->connection
        );

        // Event should not be modified
        $this->event->expects($this->never())
            ->method('getParam');

        $listener($this->event);
    }

    public function testNonLoggedUserBypassesFilter()
    {
        // Setup
        $this->authService->method('getIdentity')->willReturn(null);

        $listener = new ModifyQueryListener(
            $this->authService,
            $this->userSettings,
            $this->connection
        );

        // Event should not be modified
        $this->event->expects($this->never())
            ->method('getParam');

        $listener($this->event);
    }

    public function testRegularUserWithSitePermissions()
    {
        // Setup
        $userId = 1;
        $siteIds = [1, 2, 3];
        
        $this->user->method('getRole')->willReturn('editor');
        $this->user->method('getId')->willReturn($userId);
        $this->authService->method('getIdentity')->willReturn($this->user);

        $stmt = $this->createMock(\Doctrine\DBAL\Result::class);
        $stmt->method('fetchFirstColumn')->willReturn($siteIds);

        $this->connection->method('executeQuery')
            ->willReturn($stmt);

        $this->userSettings->method('get')
            ->with('limit_to_granted_sites', 1)
            ->willReturn(1);

        $this->queryBuilder->method('getRootAliases')
            ->willReturn(['root']);

        $this->queryBuilder->expects($this->once())
            ->method('innerJoin')
            ->with('root.sites', 'site')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('site.id IN (:siteIds)')
            ->willReturn($this->queryBuilder);

        $this->event->method('getParam')
            ->with('queryBuilder')
            ->willReturn($this->queryBuilder);

        $listener = new ModifyQueryListener(
            $this->authService,
            $this->userSettings,
            $this->connection
        );

        $listener($this->event);
    }
}
